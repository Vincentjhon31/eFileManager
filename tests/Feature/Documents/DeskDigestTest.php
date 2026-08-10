<?php

namespace Tests\Feature\Documents;

use App\Enums\ActionRequested;
use App\Enums\Role as RoleEnum;
use App\Livewire\Alerts;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use App\Notifications\DeskDigest;
use App\Services\DocumentRoutingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The morning digest.
 *
 * A daily message only survives if it is worth opening, so the rules being
 * tested here are mostly about restraint: nothing to say, nothing sent; and the
 * office-wide summary goes only to people who can act on it.
 */
class DeskDigestTest extends TestCase
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
        $this->memo = DocumentType::factory()->create();
    }

    private function user(RoleEnum $role, ?Department $office = null): User
    {
        $user = User::factory()->inDepartment($office ?? $this->mayor)->create();
        $user->assignRole($role->value);

        return $user;
    }

    private function sendDigests(): void
    {
        $this->artisan('documents:send-desk-digests')->assertSuccessful();
    }

    public function test_a_clear_desk_gets_no_message_at_all(): void
    {
        Notification::fake();

        $this->user(RoleEnum::ReceivingClerk);

        $this->sendDigests();

        Notification::assertNothingSent();
    }

    public function test_someone_holding_an_overdue_document_is_told_about_it(): void
    {
        Notification::fake();

        $clerk = $this->user(RoleEnum::ReceivingClerk);

        $document = Document::factory()
            ->forOffice($this->mayor)
            ->heldBy($this->mayor, $clerk)
            ->create([
                'document_type_id' => $this->memo->id,
                'subject' => 'Overdue purchase request',
                'due_at' => now()->subDays(3),
            ]);

        $this->sendDigests();

        Notification::assertSentTo($clerk, DeskDigest::class, function (DeskDigest $digest) use ($document) {
            return collect($digest->mine)->contains('tracking_no', $document->tracking_no);
        });
    }

    public function test_a_document_due_shortly_is_included_before_it_is_late(): void
    {
        Notification::fake();

        $clerk = $this->user(RoleEnum::ReceivingClerk);

        Document::factory()->forOffice($this->mayor)->heldBy($this->mayor, $clerk)->create([
            'document_type_id' => $this->memo->id,
            'due_at' => now()->addDay(),
        ]);

        $this->sendDigests();

        Notification::assertSentTo($clerk, DeskDigest::class,
            fn (DeskDigest $digest) => count($digest->mine) === 1);
    }

    public function test_a_document_due_next_week_is_left_alone(): void
    {
        Notification::fake();

        $clerk = $this->user(RoleEnum::ReceivingClerk);

        Document::factory()->forOffice($this->mayor)->heldBy($this->mayor, $clerk)->create([
            'document_type_id' => $this->memo->id,
            'due_at' => now()->addDays(9),
        ]);

        $this->sendDigests();

        Notification::assertNothingSent();
    }

    public function test_the_office_summary_counts_what_is_waiting_to_be_received(): void
    {
        Notification::fake();

        $clerk = $this->user(RoleEnum::ReceivingClerk);
        $sender = $this->user(RoleEnum::ReceivingClerk, $this->budget);

        // Budget is not onboarded, so its staff get nothing — but a document it
        // sends to the Mayor's Office still lands in their incoming count.
        $document = $this->routing->register([
            'document_type_id' => $this->memo->id,
            'subject' => 'Endorsement from Budget',
            'origin_department_id' => $this->budget->id,
        ], $sender);

        $this->routing->release($document, $this->mayor, ActionRequested::ForApproval, $sender);

        $this->sendDigests();

        Notification::assertSentTo($clerk, DeskDigest::class,
            fn (DeskDigest $digest) => $digest->incoming === 1 && $digest->includesOfficeSummary);

        Notification::assertNotSentTo($sender, DeskDigest::class);
    }

    /**
     * Ten people in one office receiving the same office-wide backlog every
     * morning is how a digest becomes noise. Staff who cannot receive hear only
     * about papers on their own desk.
     */
    public function test_ordinary_staff_are_not_sent_the_office_wide_summary(): void
    {
        Notification::fake();

        $staff = $this->user(RoleEnum::Staff);
        $clerk = $this->user(RoleEnum::ReceivingClerk);
        $sender = $this->user(RoleEnum::ReceivingClerk, $this->budget);

        $document = $this->routing->register([
            'document_type_id' => $this->memo->id,
            'subject' => 'Endorsement from Budget',
            'origin_department_id' => $this->budget->id,
        ], $sender);
        $this->routing->release($document, $this->mayor, ActionRequested::ForApproval, $sender);

        $this->sendDigests();

        Notification::assertSentTo($clerk, DeskDigest::class);
        Notification::assertNotSentTo($staff, DeskDigest::class);
    }

    public function test_staff_of_an_office_that_has_not_onboarded_are_left_alone(): void
    {
        Notification::fake();

        $budgetClerk = $this->user(RoleEnum::ReceivingClerk, $this->budget);

        Document::factory()->forOffice($this->budget)->heldBy($this->budget, $budgetClerk)->create([
            'document_type_id' => $this->memo->id,
            'due_at' => now()->subDays(3),
        ]);

        $this->sendDigests();

        Notification::assertNothingSent();
    }

    public function test_a_deactivated_employee_is_not_written_to(): void
    {
        Notification::fake();

        $clerk = $this->user(RoleEnum::ReceivingClerk);
        $clerk->update(['is_active' => false]);

        Document::factory()->forOffice($this->mayor)->heldBy($this->mayor, $clerk)->create([
            'document_type_id' => $this->memo->id,
            'due_at' => now()->subDays(3),
        ]);

        $this->sendDigests();

        Notification::assertNothingSent();
    }

    public function test_a_dry_run_reports_without_sending(): void
    {
        Notification::fake();

        $clerk = $this->user(RoleEnum::ReceivingClerk);

        Document::factory()->forOffice($this->mayor)->heldBy($this->mayor, $clerk)->create([
            'document_type_id' => $this->memo->id,
            'due_at' => now()->subDays(3),
        ]);

        $this->artisan('documents:send-desk-digests', ['--dry-run' => true])
            ->expectsOutputToContain($clerk->email)
            ->expectsOutputToContain('Would write to 1 of 1')
            ->assertSuccessful();

        Notification::assertNothingSent();
    }

    /*
    |--------------------------------------------------------------------------
    | In-app alerts
    |--------------------------------------------------------------------------
    */

    public function test_the_digest_lands_in_the_in_app_alerts_list(): void
    {
        $clerk = $this->user(RoleEnum::ReceivingClerk);

        $document = Document::factory()->forOffice($this->mayor)->heldBy($this->mayor, $clerk)->create([
            'document_type_id' => $this->memo->id,
            'subject' => 'Overdue purchase request',
            'due_at' => now()->subDays(3),
        ]);

        $this->sendDigests();

        $this->assertSame(1, $clerk->fresh()->unreadNotifications()->count());

        Livewire::actingAs($clerk)
            ->test(Alerts::class)
            ->assertSee($document->tracking_no)
            ->assertSee('Overdue purchase request');
    }

    public function test_an_alert_can_be_marked_as_read_without_touching_the_record(): void
    {
        $clerk = $this->user(RoleEnum::ReceivingClerk);

        Document::factory()->forOffice($this->mayor)->heldBy($this->mayor, $clerk)->create([
            'document_type_id' => $this->memo->id,
            'due_at' => now()->subDays(3),
        ]);

        $this->sendDigests();

        Livewire::actingAs($clerk)
            ->test(Alerts::class)
            ->call('markAllAsRead');

        $this->assertSame(0, $clerk->fresh()->unreadNotifications()->count());
        $this->assertSame(1, $clerk->fresh()->notifications()->count());
    }

    public function test_an_alert_is_addressed_to_one_person_and_read_by_no_one_else(): void
    {
        $clerk = $this->user(RoleEnum::ReceivingClerk);

        // Ordinary staff get no office-wide summary, so a document assigned to
        // somebody else is simply not their business.
        $colleague = $this->user(RoleEnum::Staff);

        Document::factory()->forOffice($this->mayor)->heldBy($this->mayor, $clerk)->create([
            'document_type_id' => $this->memo->id,
            'subject' => 'Only for the assignee',
            'due_at' => now()->subDays(3),
        ]);

        $this->sendDigests();

        $this->assertSame(1, $clerk->fresh()->notifications()->count());
        $this->assertSame(0, $colleague->fresh()->notifications()->count());

        Livewire::actingAs($colleague)
            ->test(Alerts::class)
            ->assertDontSee('Only for the assignee');
    }

    /**
     * A receiving clerk is responsible for the office's backlog, so their
     * digest covers documents sitting with colleagues too. That is the point of
     * the office summary, not a leak — it is the same office, and they could
     * see every one of these on My Desk anyway.
     */
    public function test_a_receiving_clerk_hears_about_the_whole_offices_overdue_backlog(): void
    {
        $assignee = $this->user(RoleEnum::Staff);
        $clerk = $this->user(RoleEnum::ReceivingClerk);

        Document::factory()->forOffice($this->mayor)->heldBy($this->mayor, $assignee)->create([
            'document_type_id' => $this->memo->id,
            'subject' => 'Sitting with a colleague',
            'due_at' => now()->subDays(3),
        ]);

        $this->sendDigests();

        Livewire::actingAs($clerk)
            ->test(Alerts::class)
            ->assertSee('Sitting with a colleague');
    }
}
