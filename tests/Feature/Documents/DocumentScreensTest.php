<?php

namespace Tests\Feature\Documents;

use App\Enums\ActionRequested;
use App\Enums\DocumentStatus;
use App\Enums\ReceiptMethod;
use App\Enums\Role as RoleEnum;
use App\Livewire\Desk\Index as Desk;
use App\Livewire\Documents\Register;
use App\Livewire\Documents\Show;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use App\Services\DocumentRoutingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The screens clerks actually use.
 *
 * The state machine is proven in DocumentRoutingTest; what is checked here is
 * that the screens are wired to it — that a refusal reaches the user as a
 * readable message rather than a stack trace, and that the desk answers the
 * questions it claims to.
 */
class DocumentScreensTest extends TestCase
{
    use RefreshDatabase;

    private Department $mayor;

    private Department $budget;

    private DocumentType $memo;

    private DocumentRoutingService $routing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->routing = app(DocumentRoutingService::class);
        $this->mayor = Department::factory()->onboarded()->create(['code' => 'MO', 'short_name' => "Mayor's Office"]);
        $this->budget = Department::factory()->create(['code' => 'MBO', 'short_name' => 'Budget']);
        $this->memo = DocumentType::factory()->create(['code' => 'MEMO', 'name' => 'Memorandum']);
    }

    private function clerk(?Department $office = null, RoleEnum $role = RoleEnum::ReceivingClerk): User
    {
        $user = User::factory()->inDepartment($office ?? $this->mayor)->create();
        $user->assignRole($role->value);

        return $user;
    }

    private function registered(User $by): Document
    {
        return $this->routing->register([
            'document_type_id' => $this->memo->id,
            'subject' => 'Budget hearing schedule for FY 2027',
            'origin_department_id' => $this->mayor->id,
        ], $by);
    }

    /*
    |--------------------------------------------------------------------------
    | Registering
    |--------------------------------------------------------------------------
    */

    public function test_a_clerk_registers_a_document_and_is_taken_to_its_tracking_number(): void
    {
        $clerk = $this->clerk();

        Livewire::actingAs($clerk)
            ->test(Register::class)
            ->set('document_type_id', $this->memo->id)
            ->set('subject', 'Request for the 2027 budget briefing')
            ->set('origin_department_id', $this->budget->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $document = Document::firstOrFail();

        $this->assertSame(DocumentStatus::Draft, $document->status);
        $this->assertMatchesRegularExpression('/^BGB-MO-\d{4}-\d{2}-0001$/', $document->tracking_no);
    }

    public function test_the_origin_office_must_be_chosen_rather_than_assumed(): void
    {
        Livewire::actingAs($this->clerk())
            ->test(Register::class)
            ->set('document_type_id', $this->memo->id)
            ->set('subject', 'No origin given')
            ->call('save')
            ->assertHasErrors('origin_department_id');

        $this->assertSame(0, Document::count());
    }

    public function test_staff_without_permission_cannot_reach_the_registration_screen(): void
    {
        $viewer = User::factory()->inDepartment($this->mayor)->create();

        $this->actingAs($viewer)->get(route('documents.register'))->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Acting on a document
    |--------------------------------------------------------------------------
    */

    public function test_a_document_is_released_from_its_own_page(): void
    {
        $clerk = $this->clerk();
        $document = $this->registered($clerk);

        Livewire::actingAs($clerk)
            ->test(Show::class, ['document' => $document])
            ->call('open', 'release')
            ->set('to_department_id', $this->budget->id)
            ->set('action_requested', ActionRequested::ForApproval->value)
            ->set('route_remarks', 'Please prepare the figures.')
            ->call('release')
            ->assertHasNoErrors();

        $document->refresh();
        $this->assertSame(DocumentStatus::InTransit, $document->status);
        $this->assertSame($this->budget->id, $document->openRoute->to_department_id);
    }

    /**
     * The service's refusals are written for a records clerk. They must arrive
     * on screen as messages, not as a 500.
     */
    public function test_a_refusal_from_the_routing_engine_is_shown_as_a_readable_error(): void
    {
        $clerk = $this->clerk();
        $document = $this->registered($clerk);

        Livewire::actingAs($clerk)
            ->test(Show::class, ['document' => $document])
            ->call('open', 'release')
            ->set('to_department_id', $this->mayor->id) // the office already holding it
            ->set('action_requested', ActionRequested::ForApproval->value)
            ->call('release')
            ->assertHasErrors('routing');

        $this->assertSame(DocumentStatus::Draft, $document->fresh()->status);
    }

    public function test_the_receive_panel_offers_only_a_paper_receipt_for_an_office_that_has_not_onboarded(): void
    {
        $clerk = $this->clerk();
        $document = $this->registered($clerk);
        $this->routing->release($document, $this->budget, ActionRequested::ForApproval, $clerk);

        Livewire::actingAs($clerk)
            ->test(Show::class, ['document' => $document])
            ->call('open', 'receive')
            ->assertSet('receipt_method', ReceiptMethod::Manual->value);
    }

    public function test_a_paper_receipt_is_recorded_from_the_document_page(): void
    {
        $clerk = $this->clerk();
        $document = $this->registered($clerk);
        $this->routing->release($document, $this->budget, ActionRequested::ForApproval, $clerk);

        Livewire::actingAs($clerk)
            ->test(Show::class, ['document' => $document])
            ->call('open', 'receive')
            ->set('receipt_method', ReceiptMethod::Manual->value)
            ->set('received_by_name', 'Rosalinda Manalo')
            ->call('receive')
            ->assertHasNoErrors();

        $document->refresh();
        $this->assertSame(DocumentStatus::Received, $document->status);
        $this->assertSame($this->budget->id, $document->current_holder_department_id);
    }

    public function test_a_paper_receipt_without_a_signatory_is_refused_on_screen(): void
    {
        $clerk = $this->clerk();
        $document = $this->registered($clerk);
        $this->routing->release($document, $this->budget, ActionRequested::ForApproval, $clerk);

        Livewire::actingAs($clerk)
            ->test(Show::class, ['document' => $document])
            ->call('open', 'receive')
            ->set('receipt_method', ReceiptMethod::Manual->value)
            ->call('receive')
            ->assertHasErrors('received_by_name');
    }

    public function test_an_office_that_does_not_hold_a_document_is_offered_no_actions(): void
    {
        $clerk = $this->clerk();
        $document = $this->registered($clerk);
        $this->routing->release($document, $this->budget, ActionRequested::ForApproval, $clerk);
        $this->routing->receive($document, $clerk, ReceiptMethod::Manual, receivedByName: 'Rosalinda Manalo');

        // Budget hold it now, so the Mayor's Office cannot release or act.
        Livewire::actingAs($clerk)
            ->test(Show::class, ['document' => $document])
            ->assertDontSee('Send to another office')
            ->assertDontSee('Close or withdraw');
    }

    /*
    |--------------------------------------------------------------------------
    | My Desk
    |--------------------------------------------------------------------------
    */

    public function test_the_desk_shows_what_has_been_sent_to_my_office(): void
    {
        $mayorClerk = $this->clerk();
        $legal = Department::factory()->onboarded()->create(['code' => 'LEGAL', 'short_name' => 'Legal']);
        $legalClerk = $this->clerk($legal);

        $document = $this->registered($mayorClerk);
        $this->routing->release($document, $legal, ActionRequested::ForComment, $mayorClerk);

        Livewire::actingAs($legalClerk)
            ->test(Desk::class)
            ->assertSet('tab', 'incoming')
            ->assertSee($document->tracking_no)
            ->assertSee("Mayor's Office");
    }

    public function test_the_desk_chase_list_shows_what_my_office_sent_and_nobody_has_signed_for(): void
    {
        $clerk = $this->clerk();
        $document = $this->registered($clerk);
        $this->routing->release($document, $this->budget, ActionRequested::ForApproval, $clerk);

        Livewire::actingAs($clerk)
            ->test(Desk::class)
            ->call('selectTab', 'awaiting')
            ->assertSee($document->tracking_no)
            ->assertSee('Budget');
    }

    public function test_a_document_leaves_the_chase_list_once_it_is_signed_for(): void
    {
        $clerk = $this->clerk();
        $document = $this->registered($clerk);
        $this->routing->release($document, $this->budget, ActionRequested::ForApproval, $clerk);
        $this->routing->receive($document, $clerk, ReceiptMethod::Manual, receivedByName: 'Rosalinda Manalo');

        Livewire::actingAs($clerk)
            ->test(Desk::class)
            ->call('selectTab', 'awaiting')
            ->assertDontSee($document->tracking_no);
    }

    public function test_the_desk_shows_what_my_office_is_holding(): void
    {
        $clerk = $this->clerk();
        $document = $this->registered($clerk);

        Livewire::actingAs($clerk)
            ->test(Desk::class)
            ->call('selectTab', 'desk')
            ->assertDontSee($document->tracking_no, 'A draft is not yet on anybody\'s desk.');

        $this->routing->release($document, $this->budget, ActionRequested::ForApproval, $clerk);
        $this->routing->receive($document, $clerk, ReceiptMethod::Manual, receivedByName: 'Rosalinda Manalo');
        $this->routing->returnToSender($document, $this->clerk($this->budget), 'Missing attachment.');
        $this->routing->receive($document, $clerk);

        Livewire::actingAs($clerk)
            ->test(Desk::class)
            ->call('selectTab', 'desk')
            ->assertSee($document->tracking_no);
    }

    public function test_the_desk_counts_what_is_overdue(): void
    {
        $clerk = $this->clerk();
        $document = $this->registered($clerk);

        $this->routing->release(
            $document, $this->budget, ActionRequested::ForCompliance, $clerk,
            dueAt: now()->subDays(2),
        );
        $this->routing->receive($document, $clerk, ReceiptMethod::Manual, receivedByName: 'Rosalinda Manalo');

        // It is Budget's problem now, and Budget's count.
        $budgetClerk = $this->clerk($this->budget);

        $this->assertSame(1, Livewire::actingAs($budgetClerk)->test(Desk::class)->instance()->counts()['overdue']);
        $this->assertSame(0, Livewire::actingAs($clerk)->test(Desk::class)->instance()->counts()['overdue']);
    }

    public function test_a_user_with_no_office_sees_an_empty_desk_rather_than_an_error(): void
    {
        $unassigned = User::factory()->create(['department_id' => null]);
        $unassigned->assignRole(RoleEnum::Staff->value);

        Livewire::actingAs($unassigned)
            ->test(Desk::class)
            ->assertOk()
            ->assertSet('tab', 'incoming');
    }
}
