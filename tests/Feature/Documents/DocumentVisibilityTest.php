<?php

namespace Tests\Feature\Documents;

use App\Enums\ActionRequested;
use App\Enums\Role as RoleEnum;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use App\Policies\DocumentPolicy;
use App\Services\DocumentRoutingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who can see which documents.
 *
 * The rule staff are taught is one sentence — *you can see a document if it has
 * passed through your office* — and confidential documents narrow that further.
 * Under RA 10173 a Budget clerk reading the Mayor's personnel correspondence is
 * a reportable breach, not a cosmetic bug, so this is enforced at the query
 * layer where no filter or crafted parameter can reach past it.
 */
class DocumentVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Department $mayor;

    private Department $budget;

    private Department $hrmo;

    private DocumentType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->mayor = Department::factory()->onboarded()->create(['code' => 'MO', 'short_name' => "Mayor's Office"]);
        $this->budget = Department::factory()->onboarded()->create(['code' => 'MBO', 'short_name' => 'Budget']);
        $this->hrmo = Department::factory()->onboarded()->create(['code' => 'HRMO', 'short_name' => 'HR']);
        $this->type = DocumentType::factory()->create();
    }

    private function staffOf(Department $office, RoleEnum $role = RoleEnum::Staff): User
    {
        $user = User::factory()->inDepartment($office)->create();
        $user->assignRole($role->value);

        return $user;
    }

    private function documentIn(Department $office, array $overrides = []): Document
    {
        return Document::factory()
            ->forOffice($office)
            ->create(['document_type_id' => $this->type->id] + $overrides);
    }

    private function visibleTo(User $user): array
    {
        return Document::query()->visibleTo($user)->pluck('id')->all();
    }

    /*
    |--------------------------------------------------------------------------
    | The baseline: it passed through my office
    |--------------------------------------------------------------------------
    */

    public function test_an_office_sees_the_documents_it_registered(): void
    {
        $mine = $this->documentIn($this->mayor);

        $this->assertContains($mine->id, $this->visibleTo($this->staffOf($this->mayor)));
    }

    public function test_an_office_does_not_see_an_unrelated_offices_documents(): void
    {
        $theirs = $this->documentIn($this->hrmo);

        $this->assertNotContains($theirs->id, $this->visibleTo($this->staffOf($this->budget)));
    }

    public function test_an_office_sees_a_document_the_moment_it_is_sent_to_them(): void
    {
        $clerk = $this->staffOf($this->mayor);
        $budgetStaff = $this->staffOf($this->budget);
        $routing = app(DocumentRoutingService::class);

        $document = $routing->register([
            'document_type_id' => $this->type->id,
            'subject' => 'Budget hearing schedule',
            'origin_department_id' => $this->mayor->id,
        ], $clerk);

        $this->assertNotContains($document->id, $this->visibleTo($budgetStaff));

        $routing->release($document, $this->budget, ActionRequested::ForApproval, $clerk);

        $this->assertContains(
            $document->id,
            $this->visibleTo($budgetStaff),
            'An office must be able to see what it has been sent before it signs for it.',
        );
    }

    public function test_an_office_keeps_seeing_a_document_after_it_has_passed_through(): void
    {
        $clerk = $this->staffOf($this->mayor);
        $budgetStaff = $this->staffOf($this->budget);
        $routing = app(DocumentRoutingService::class);

        $document = $routing->register([
            'document_type_id' => $this->type->id,
            'subject' => 'Purchase request for office supplies',
            'origin_department_id' => $this->mayor->id,
        ], $clerk);

        $routing->release($document, $this->budget, ActionRequested::ForApproval, $clerk);
        $routing->receive($document, $budgetStaff);
        $routing->release($document, $this->hrmo, ActionRequested::ForInformation, $budgetStaff);

        // Budget no longer hold it, but they handled it and remain accountable
        // for what they did with it.
        $this->assertContains($document->id, $this->visibleTo($budgetStaff));
        $this->assertContains($document->id, $this->visibleTo($clerk));
        $this->assertContains($document->id, $this->visibleTo($this->staffOf($this->hrmo)));
    }

    public function test_a_system_administrator_sees_every_office(): void
    {
        $everywhere = [
            $this->documentIn($this->mayor)->id,
            $this->documentIn($this->budget)->id,
            $this->documentIn($this->hrmo)->id,
        ];

        $visible = $this->visibleTo($this->staffOf($this->mayor, RoleEnum::SuperAdmin));

        foreach ($everywhere as $id) {
            $this->assertContains($id, $visible);
        }
    }

    public function test_a_user_with_no_office_sees_nothing(): void
    {
        $this->documentIn($this->mayor);

        $unassigned = User::factory()->create(['department_id' => null]);
        $unassigned->assignRole(RoleEnum::Staff->value);

        $this->assertSame([], $this->visibleTo($unassigned));
    }

    public function test_the_scope_denies_by_default_when_there_is_no_user(): void
    {
        $this->documentIn($this->mayor);

        $this->assertSame([], Document::query()->visibleTo(null)->pluck('id')->all());
    }

    /*
    |--------------------------------------------------------------------------
    | Confidential narrows the baseline; public never widens it
    |--------------------------------------------------------------------------
    */

    public function test_a_confidential_document_is_hidden_from_ordinary_staff_in_the_same_office(): void
    {
        $confidential = $this->documentIn($this->mayor, ['confidentiality' => 'confidential']);

        $this->assertNotContains($confidential->id, $this->visibleTo($this->staffOf($this->mayor)));
    }

    public function test_a_confidential_document_is_visible_to_the_office_head(): void
    {
        $confidential = $this->documentIn($this->mayor, ['confidentiality' => 'confidential']);

        $this->assertContains(
            $confidential->id,
            $this->visibleTo($this->staffOf($this->mayor, RoleEnum::Approver)),
        );
        $this->assertContains(
            $confidential->id,
            $this->visibleTo($this->staffOf($this->mayor, RoleEnum::DepartmentAdmin)),
        );
    }

    public function test_a_confidential_document_is_visible_to_whoever_is_holding_it(): void
    {
        $clerk = $this->staffOf($this->mayor);

        $confidential = $this->documentIn($this->mayor, [
            'confidentiality' => 'confidential',
            'current_holder_user_id' => $clerk->id,
        ]);

        $this->assertContains($confidential->id, $this->visibleTo($clerk));
    }

    public function test_a_confidential_document_is_visible_to_whoever_registered_it(): void
    {
        $clerk = $this->staffOf($this->mayor);

        $confidential = $this->documentIn($this->mayor, [
            'confidentiality' => 'confidential',
            'created_by' => $clerk->id,
        ]);

        $this->assertContains($confidential->id, $this->visibleTo($clerk));
    }

    public function test_a_confidential_document_is_still_invisible_to_another_offices_head(): void
    {
        $confidential = $this->documentIn($this->mayor, ['confidentiality' => 'confidential']);

        // Confidentiality narrows within an office. It never grants an office
        // head a view into someone else's.
        $this->assertNotContains(
            $confidential->id,
            $this->visibleTo($this->staffOf($this->budget, RoleEnum::Approver)),
        );
    }

    /**
     * The naming invites the wrong assumption, so it gets its own test: marking
     * a document for public disclosure makes it *eligible* for the portal. It
     * does not open it up across the municipal hall, and publishing remains a
     * separate, deliberate act.
     */
    public function test_marking_a_document_for_public_disclosure_does_not_widen_internal_access(): void
    {
        $public = $this->documentIn($this->mayor, ['confidentiality' => 'public']);

        $this->assertNotContains($public->id, $this->visibleTo($this->staffOf($this->budget)));
    }

    /*
    |--------------------------------------------------------------------------
    | The policy and the scope must agree
    |--------------------------------------------------------------------------
    */

    public function test_the_policy_and_the_query_scope_reach_the_same_answer(): void
    {
        $policy = app(DocumentPolicy::class);

        $mine = $this->documentIn($this->mayor);
        $theirs = $this->documentIn($this->hrmo);
        $confidential = $this->documentIn($this->mayor, ['confidentiality' => 'confidential']);

        $staff = $this->staffOf($this->mayor);
        $head = $this->staffOf($this->mayor, RoleEnum::Approver);

        foreach ([[$staff, $mine, true], [$staff, $theirs, false], [$staff, $confidential, false],
            [$head, $confidential, true]] as [$user, $document, $expected]) {
            $this->assertSame($expected, $policy->view($user, $document));
            $this->assertSame($expected, in_array($document->id, $this->visibleTo($user), true));
        }
    }

    public function test_a_document_page_cannot_be_opened_by_an_unrelated_office(): void
    {
        $theirs = $this->documentIn($this->hrmo);

        $this->actingAs($this->staffOf($this->budget))
            ->get(route('documents.show', $theirs))
            ->assertForbidden();
    }

    public function test_a_document_page_opens_for_the_office_that_registered_it(): void
    {
        $mine = $this->documentIn($this->mayor);

        $this->actingAs($this->staffOf($this->mayor))
            ->get(route('documents.show', $mine))
            ->assertOk()
            ->assertSee($mine->tracking_no);
    }
}
