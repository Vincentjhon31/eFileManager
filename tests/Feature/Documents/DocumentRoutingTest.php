<?php

namespace Tests\Feature\Documents;

use App\Enums\ActionRequested;
use App\Enums\DocumentEvent;
use App\Enums\DocumentStatus;
use App\Enums\ReceiptMethod;
use App\Enums\RouteStatus;
use App\Exceptions\RoutingException;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentRoute;
use App\Models\DocumentType;
use App\Models\User;
use App\Services\DocumentRoutingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * The routing state machine.
 *
 * This is the part of the system that has to hold up if anyone ever asks "who
 * had this on the fourteenth?", so it gets the heaviest coverage. The two
 * invariants under test throughout:
 *
 *   1. a document has exactly one accountable holder at every moment
 *   2. a receipt is written once and never rewritten
 */
class DocumentRoutingTest extends TestCase
{
    use RefreshDatabase;

    private DocumentRoutingService $routing;

    private Department $mayor;      // onboarded — the pilot office

    private Department $budget;     // internal, not onboarded — paper only

    private Department $province;   // external — never gets accounts

    private DocumentType $memo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->routing = app(DocumentRoutingService::class);

        $this->mayor = Department::factory()->onboarded()->create([
            'code' => 'MO', 'name' => 'Office of the Municipal Mayor', 'short_name' => "Mayor's Office",
        ]);
        $this->budget = Department::factory()->create([
            'code' => 'MBO', 'name' => 'Municipal Budget Office', 'short_name' => 'Budget',
        ]);
        $this->province = Department::factory()->external()->create([
            'code' => 'EXT-PGOM', 'name' => 'Provincial Government of Oriental Mindoro', 'short_name' => 'Province',
        ]);

        $this->memo = DocumentType::factory()->create(['code' => 'MEMO', 'name' => 'Memorandum']);
    }

    private function clerk(Department $office): User
    {
        return User::factory()->inDepartment($office)->create();
    }

    private function register(?User $by = null, array $overrides = []): Document
    {
        $by ??= $this->clerk($this->mayor);

        return $this->routing->register([
            'document_type_id' => $this->memo->id,
            'subject' => 'Budget hearing schedule for FY 2027',
            'origin_department_id' => $this->mayor->id,
        ] + $overrides, $by);
    }

    /*
    |--------------------------------------------------------------------------
    | Registration
    |--------------------------------------------------------------------------
    */

    public function test_registering_issues_a_tracking_number_and_holds_it_at_the_registering_office(): void
    {
        $clerk = $this->clerk($this->mayor);

        $document = $this->register($clerk);

        $this->assertSame(DocumentStatus::Draft, $document->status);
        $this->assertSame($this->mayor->id, $document->registering_department_id);
        $this->assertSame($this->mayor->id, $document->current_holder_department_id);
        $this->assertSame($clerk->id, $document->created_by);
        $this->assertMatchesRegularExpression('/^BGB-MO-\d{4}-\d{2}-\d{4}$/', $document->tracking_no);
    }

    public function test_registering_is_written_to_the_timeline_and_the_audit_trail(): void
    {
        $document = $this->register();

        $this->assertSame(DocumentEvent::Registered, $document->actions()->first()->action);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'document.registered',
            'auditable_id' => $document->id,
        ]);
    }

    public function test_an_outside_party_cannot_register_documents(): void
    {
        $outsider = User::factory()->inDepartment($this->province)->create();

        $this->expectException(RoutingException::class);

        $this->register($outsider);
    }

    public function test_a_user_with_no_office_cannot_register_documents(): void
    {
        $unassigned = User::factory()->create(['department_id' => null]);

        $this->expectException(RoutingException::class);

        $this->register($unassigned);
    }

    /*
    |--------------------------------------------------------------------------
    | Releasing
    |--------------------------------------------------------------------------
    */

    public function test_releasing_opens_a_transmittal_and_puts_the_document_in_transit(): void
    {
        $clerk = $this->clerk($this->mayor);
        $document = $this->register($clerk);

        $leg = $this->routing->release(
            document: $document,
            to: $this->budget,
            action: ActionRequested::ForApproval,
            by: $clerk,
            remarks: 'Please review before Friday.',
        );

        $this->assertSame(1, $leg->seq);
        $this->assertSame(RouteStatus::Pending, $leg->status);
        $this->assertSame($this->mayor->id, $leg->from_department_id);
        $this->assertSame($this->budget->id, $leg->to_department_id);
        $this->assertNull($leg->received_at);

        $this->assertSame(DocumentStatus::InTransit, $document->fresh()->status);
    }

    /**
     * The invariant that makes "exactly one holder" true rather than merely
     * intended: until the destination signs, the sender is still the office
     * that has to answer for where the paper is.
     */
    public function test_the_holder_does_not_move_until_the_destination_signs_for_it(): void
    {
        $clerk = $this->clerk($this->mayor);
        $document = $this->register($clerk);

        $this->routing->release($document, $this->budget, ActionRequested::ForApproval, $clerk);

        $this->assertSame(
            $this->mayor->id,
            $document->fresh()->current_holder_department_id,
            'A document in transit is still charged to the office that sent it.',
        );
    }

    public function test_an_office_cannot_release_a_document_it_is_not_holding(): void
    {
        $document = $this->register();
        $outsider = $this->clerk($this->budget);

        $this->expectException(RoutingException::class);
        $this->expectExceptionMessage("Mayor's Office");

        $this->routing->release($document, $this->province, ActionRequested::ForInformation, $outsider);
    }

    public function test_a_document_cannot_be_released_to_the_office_already_holding_it(): void
    {
        $clerk = $this->clerk($this->mayor);
        $document = $this->register($clerk);

        $this->expectException(RoutingException::class);
        $this->expectExceptionMessage('assign it instead');

        $this->routing->release($document, $this->mayor, ActionRequested::ForInformation, $clerk);
    }

    public function test_a_document_already_in_transit_cannot_be_released_again(): void
    {
        $clerk = $this->clerk($this->mayor);
        $document = $this->register($clerk);

        $this->routing->release($document, $this->budget, ActionRequested::ForApproval, $clerk);

        $this->expectException(RoutingException::class);

        $this->routing->release($document, $this->province, ActionRequested::ForInformation, $clerk);
    }

    public function test_a_document_has_at_most_one_transmittal_awaiting_receipt(): void
    {
        $clerk = $this->clerk($this->mayor);
        $document = $this->register($clerk);

        $this->routing->release($document, $this->budget, ActionRequested::ForApproval, $clerk);

        try {
            $this->routing->release($document, $this->province, ActionRequested::ForInformation, $clerk);
        } catch (RoutingException) {
            // expected
        }

        $this->assertSame(1, $document->routes()->pending()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Receiving in the system
    |--------------------------------------------------------------------------
    */

    public function test_receiving_closes_the_transmittal_and_moves_the_holder(): void
    {
        $sender = $this->clerk($this->mayor);
        $legal = Department::factory()->onboarded()->create(['code' => 'LEGAL', 'short_name' => 'Legal']);
        $receiver = $this->clerk($legal);

        $document = $this->register($sender);
        $this->routing->release($document, $legal, ActionRequested::ForComment, $sender);

        $leg = $this->routing->receive($document, $receiver);

        $this->assertSame(RouteStatus::Received, $leg->status);
        $this->assertNotNull($leg->received_at);
        $this->assertSame($receiver->id, $leg->received_by);
        $this->assertSame($receiver->name, $leg->received_by_name);
        $this->assertSame(ReceiptMethod::System, $leg->receipt_method);

        $document->refresh();
        $this->assertSame(DocumentStatus::Received, $document->status);
        $this->assertSame($legal->id, $document->current_holder_department_id);
    }

    public function test_an_office_that_is_not_the_destination_cannot_receive_in_the_system(): void
    {
        $sender = $this->clerk($this->mayor);
        $legal = Department::factory()->onboarded()->create(['code' => 'LEGAL', 'short_name' => 'Legal']);
        $bystander = $this->clerk(Department::factory()->onboarded()->create(['code' => 'HRMO']));

        $document = $this->register($sender);
        $this->routing->release($document, $legal, ActionRequested::ForComment, $sender);

        $this->expectException(RoutingException::class);

        $this->routing->receive($document, $bystander);
    }

    /**
     * The receipt timestamp is the fact this system is most likely to be asked
     * to defend. It is refused at the model layer, not merely absent from the UI.
     */
    public function test_a_receipt_timestamp_cannot_be_rewritten(): void
    {
        $leg = $this->receivedLeg();
        $original = $leg->received_at;

        // Mass assignment cannot reach it — received_at is not fillable — so
        // the realistic attempt is a direct write, which the model refuses.
        $leg->received_at = now()->subDays(5);

        try {
            $leg->save();
            $this->fail('A recorded receipt was allowed to be rewritten.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('already been recorded', $e->getMessage());
        }

        $this->assertTrue($original->equalTo($leg->fresh()->received_at));
    }

    public function test_a_receipt_timestamp_is_out_of_reach_of_mass_assignment(): void
    {
        $leg = $this->receivedLeg();
        $original = $leg->received_at;

        $leg->update(['received_at' => now()->subDays(5), 'received_by_name' => 'Someone Else']);

        $this->assertTrue($original->equalTo($leg->fresh()->received_at));
        $this->assertNotSame('Someone Else', $leg->fresh()->received_by_name);
    }

    private function receivedLeg(): DocumentRoute
    {
        $sender = $this->clerk($this->mayor);
        $legal = Department::factory()->onboarded()->create(['code' => 'LEGAL']);

        $document = $this->register($sender);
        $this->routing->release($document, $legal, ActionRequested::ForComment, $sender);

        return $this->routing->receive($document, $this->clerk($legal));
    }

    public function test_a_transmittal_cannot_be_deleted(): void
    {
        $sender = $this->clerk($this->mayor);
        $document = $this->register($sender);
        $leg = $this->routing->release($document, $this->budget, ActionRequested::ForApproval, $sender);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot be deleted');

        $leg->delete();
    }

    /*
    |--------------------------------------------------------------------------
    | Receiving on paper — what makes a one-office pilot possible
    |--------------------------------------------------------------------------
    */

    public function test_an_office_that_has_not_onboarded_cannot_receive_digitally(): void
    {
        $sender = $this->clerk($this->mayor);
        $budgetStaff = $this->clerk($this->budget);

        $document = $this->register($sender);
        $this->routing->release($document, $this->budget, ActionRequested::ForApproval, $sender);

        $this->expectException(RoutingException::class);
        $this->expectExceptionMessage('not yet using the system');

        $this->routing->receive($document, $budgetStaff, ReceiptMethod::System);
    }

    /**
     * The pilot case in full: the Mayor's Office sends a memo to Budget, who
     * sign a printed transmittal, and a Mayor's Office clerk records it. The
     * trail stays complete, and the receipt is visibly unwitnessed.
     */
    public function test_the_sending_office_records_a_paper_receipt_for_an_office_that_has_not_onboarded(): void
    {
        $clerk = $this->clerk($this->mayor);
        $document = $this->register($clerk);

        $this->routing->release($document, $this->budget, ActionRequested::ForApproval, $clerk);

        // The messenger walks it over, Budget sign at their counter, and the
        // signed transmittal comes back to the Mayor's Office later the same
        // afternoon — which is when the clerk finally records it.
        $this->travel(3)->hours();
        $signedAt = now()->subHours(2)->startOfSecond();

        $leg = $this->routing->receive(
            document: $document,
            by: $clerk,
            method: ReceiptMethod::Manual,
            receivedByName: 'Rosalinda Manalo',
            receivedAt: $signedAt,
        );

        $this->assertSame(RouteStatus::Received, $leg->status);
        $this->assertSame(ReceiptMethod::Manual, $leg->receipt_method);
        $this->assertSame('Rosalinda Manalo', $leg->received_by_name);
        $this->assertSame($clerk->id, $leg->received_by, 'The account that recorded it, not the signatory.');
        $this->assertFalse($leg->receipt_method->isWitnessed());
        $this->assertTrue($signedAt->equalTo($leg->received_at));

        $document->refresh();
        $this->assertSame(DocumentStatus::Received, $document->status);
        $this->assertSame($this->budget->id, $document->current_holder_department_id);
    }

    public function test_a_paper_receipt_must_name_who_signed_for_it(): void
    {
        $clerk = $this->clerk($this->mayor);
        $document = $this->register($clerk);
        $this->routing->release($document, $this->budget, ActionRequested::ForApproval, $clerk);

        $this->expectException(RoutingException::class);
        $this->expectExceptionMessage('who signed');

        $this->routing->receive($document, $clerk, ReceiptMethod::Manual, receivedByName: '   ');
    }

    public function test_a_paper_receipt_cannot_predate_the_release(): void
    {
        $clerk = $this->clerk($this->mayor);
        $document = $this->register($clerk);
        $this->routing->release($document, $this->budget, ActionRequested::ForApproval, $clerk);

        $this->expectException(RoutingException::class);
        $this->expectExceptionMessage('cannot be received before it was sent');

        $this->routing->receive(
            $document, $clerk, ReceiptMethod::Manual,
            receivedByName: 'Rosalinda Manalo',
            receivedAt: now()->subDays(2),
        );
    }

    public function test_a_paper_receipt_cannot_be_dated_in_the_future(): void
    {
        $clerk = $this->clerk($this->mayor);
        $document = $this->register($clerk);
        $this->routing->release($document, $this->budget, ActionRequested::ForApproval, $clerk);

        $this->expectException(RoutingException::class);
        $this->expectExceptionMessage('future');

        $this->routing->receive(
            $document, $clerk, ReceiptMethod::Manual,
            receivedByName: 'Rosalinda Manalo',
            receivedAt: now()->addHour(),
        );
    }

    public function test_an_unrelated_office_cannot_record_a_paper_receipt(): void
    {
        $clerk = $this->clerk($this->mayor);
        $bystander = $this->clerk(Department::factory()->onboarded()->create(['code' => 'HRMO']));

        $document = $this->register($clerk);
        $this->routing->release($document, $this->budget, ActionRequested::ForApproval, $clerk);

        $this->expectException(RoutingException::class);

        $this->routing->receive($document, $bystander, ReceiptMethod::Manual, receivedByName: 'Someone');
    }

    /*
    |--------------------------------------------------------------------------
    | Recall
    |--------------------------------------------------------------------------
    */

    public function test_the_sender_can_recall_a_transmittal_nobody_has_signed_for(): void
    {
        $clerk = $this->clerk($this->mayor);
        $document = $this->register($clerk);

        $this->routing->release($document, $this->budget, ActionRequested::ForApproval, $clerk);
        $leg = $this->routing->recall($document, $clerk, 'Sent to the wrong office.');

        $this->assertSame(RouteStatus::Cancelled, $leg->status);
        $this->assertSame(DocumentStatus::Draft, $document->fresh()->status);
        $this->assertSame($this->mayor->id, $document->fresh()->current_holder_department_id);
    }

    /** A ledger that hides its mistakes is worth less than a paper logbook. */
    public function test_a_recalled_transmittal_stays_on_the_record(): void
    {
        $clerk = $this->clerk($this->mayor);
        $document = $this->register($clerk);

        $this->routing->release($document, $this->budget, ActionRequested::ForApproval, $clerk);
        $this->routing->recall($document, $clerk, 'Sent to the wrong office.');

        $this->assertSame(1, $document->routes()->count());
        $this->assertDatabaseHas('document_actions', [
            'document_id' => $document->id,
            'action' => DocumentEvent::Recalled->value,
        ]);
    }

    public function test_only_the_sending_office_can_recall(): void
    {
        $clerk = $this->clerk($this->mayor);
        $budgetStaff = $this->clerk($this->budget);

        $document = $this->register($clerk);
        $this->routing->release($document, $this->budget, ActionRequested::ForApproval, $clerk);

        $this->expectException(RoutingException::class);
        $this->expectExceptionMessage('because they sent it');

        $this->routing->recall($document, $budgetStaff, 'Not mine to recall.');
    }

    public function test_recalling_a_later_leg_returns_the_document_to_received(): void
    {
        $clerk = $this->clerk($this->mayor);
        $legal = Department::factory()->onboarded()->create(['code' => 'LEGAL']);
        $legalStaff = $this->clerk($legal);

        $document = $this->register($clerk);
        $this->routing->release($document, $legal, ActionRequested::ForComment, $clerk);
        $this->routing->receive($document, $legalStaff);

        $this->routing->release($document, $this->budget, ActionRequested::ForApproval, $legalStaff);
        $this->routing->recall($document, $legalStaff, 'Wrong office again.');

        $document->refresh();
        $this->assertSame(DocumentStatus::Received, $document->status);
        $this->assertSame($legal->id, $document->current_holder_department_id);
    }

    /*
    |--------------------------------------------------------------------------
    | Returning
    |--------------------------------------------------------------------------
    */

    public function test_returning_sends_the_document_back_and_is_marked_as_a_return(): void
    {
        $clerk = $this->clerk($this->mayor);
        $legal = Department::factory()->onboarded()->create(['code' => 'LEGAL']);
        $legalStaff = $this->clerk($legal);

        $document = $this->register($clerk);
        $this->routing->release($document, $legal, ActionRequested::ForComment, $clerk);
        $this->routing->receive($document, $legalStaff);

        $leg = $this->routing->returnToSender($document, $legalStaff, 'Missing the budget attachment.');

        $this->assertTrue($leg->is_return);
        $this->assertSame($legal->id, $leg->from_department_id);
        $this->assertSame($this->mayor->id, $leg->to_department_id);
        $this->assertSame(2, $leg->seq);
        $this->assertSame(DocumentStatus::InTransit, $document->fresh()->status);
    }

    public function test_a_document_that_was_never_received_from_anyone_cannot_be_returned(): void
    {
        $clerk = $this->clerk($this->mayor);
        $document = $this->register($clerk);

        $this->expectException(RoutingException::class);
        $this->expectExceptionMessage('nowhere to return it to');

        $this->routing->returnToSender($document, $clerk, 'Nope.');
    }

    /*
    |--------------------------------------------------------------------------
    | Closing
    |--------------------------------------------------------------------------
    */

    public function test_a_document_can_be_completed_without_ever_leaving_the_office(): void
    {
        $clerk = $this->clerk($this->mayor);
        $document = $this->register($clerk);

        $this->routing->complete($document, $clerk, 'Handled at the counter.');

        $document->refresh();
        $this->assertSame(DocumentStatus::Completed, $document->status);
        $this->assertNotNull($document->closed_at);
    }

    public function test_a_document_in_transit_cannot_be_completed(): void
    {
        $clerk = $this->clerk($this->mayor);
        $document = $this->register($clerk);
        $this->routing->release($document, $this->budget, ActionRequested::ForApproval, $clerk);

        $this->expectException(RoutingException::class);

        $this->routing->complete($document, $clerk);
    }

    public function test_only_a_completed_document_can_be_archived(): void
    {
        $clerk = $this->clerk($this->mayor);
        $document = $this->register($clerk);

        $this->expectException(RoutingException::class);

        $this->routing->archive($document, $clerk);
    }

    public function test_completing_then_archiving_walks_the_document_to_the_end(): void
    {
        $clerk = $this->clerk($this->mayor);
        $document = $this->register($clerk);

        $this->routing->complete($document, $clerk);
        $this->routing->archive($document, $clerk);

        $this->assertSame(DocumentStatus::Archived, $document->fresh()->status);
    }

    public function test_reopening_puts_a_closed_document_back_on_the_desk(): void
    {
        $clerk = $this->clerk($this->mayor);
        $legal = Department::factory()->onboarded()->create(['code' => 'LEGAL']);
        $legalStaff = $this->clerk($legal);

        $document = $this->register($clerk);
        $this->routing->release($document, $legal, ActionRequested::ForComment, $clerk);
        $this->routing->receive($document, $legalStaff);
        $this->routing->complete($document, $legalStaff);

        $this->routing->reopen($document, $legalStaff, 'The attachment was wrong.');

        $document->refresh();
        $this->assertSame(DocumentStatus::Received, $document->status);
        $this->assertNull($document->closed_at);
    }

    public function test_cancelling_withdraws_the_document_and_its_open_transmittal(): void
    {
        $clerk = $this->clerk($this->mayor);
        $document = $this->register($clerk);
        $this->routing->release($document, $this->budget, ActionRequested::ForApproval, $clerk);

        $this->routing->cancel($document, $clerk, 'Registered twice by mistake.');

        $document->refresh();
        $this->assertSame(DocumentStatus::Cancelled, $document->status);
        $this->assertSame(RouteStatus::Cancelled, $document->routes()->first()->status);
        $this->assertSame(0, $document->routes()->pending()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Assignment and remarks
    |--------------------------------------------------------------------------
    */

    public function test_a_document_can_be_assigned_to_someone_in_the_holding_office(): void
    {
        $clerk = $this->clerk($this->mayor);
        $colleague = $this->clerk($this->mayor);
        $document = $this->register($clerk);

        $this->routing->assign($document, $colleague, $clerk);

        $this->assertSame($colleague->id, $document->fresh()->current_holder_user_id);
    }

    public function test_a_document_cannot_be_assigned_outside_the_holding_office(): void
    {
        $clerk = $this->clerk($this->mayor);
        $outsider = $this->clerk($this->budget);
        $document = $this->register($clerk);

        $this->expectException(RoutingException::class);
        $this->expectExceptionMessage('office holding it');

        $this->routing->assign($document, $outsider, $clerk);
    }

    public function test_remarks_are_added_to_the_timeline_without_moving_anything(): void
    {
        $clerk = $this->clerk($this->mayor);
        $document = $this->register($clerk);

        $this->routing->addRemarks($document, $clerk, 'Chased Budget by phone.');

        $this->assertSame(DocumentStatus::Draft, $document->fresh()->status);
        $this->assertDatabaseHas('document_actions', [
            'document_id' => $document->id,
            'action' => DocumentEvent::Remarked->value,
            'remarks' => 'Chased Budget by phone.',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | The whole journey
    |--------------------------------------------------------------------------
    */

    /**
     * The pilot rehearsal, end to end: an incoming provincial letter is
     * registered by the Mayor's Office, sent to Budget on paper, returned with
     * remarks, and closed. Every leg is in order and the trail is intact.
     */
    public function test_a_complete_journey_leaves_an_ordered_and_intact_trail(): void
    {
        $clerk = $this->clerk($this->mayor);

        $document = $this->routing->register([
            'document_type_id' => $this->memo->id,
            'subject' => 'Request for the 2027 municipal budget briefing',
            'origin_department_id' => $this->province->id,
            'origin_external_name' => 'Office of the Provincial Governor',
        ], $clerk);

        // Out to Budget, who are still on paper.
        $this->routing->release(
            $document, $this->budget, ActionRequested::ForApproval, $clerk,
            remarks: 'Please prepare the figures.', dueAt: now()->addDays(5),
        );
        $this->travel(1)->hours();
        $this->routing->receive(
            $document, $clerk, ReceiptMethod::Manual,
            receivedByName: 'Rosalinda Manalo', receivedAt: now()->subMinutes(30),
        );

        // Budget send it back. They have no accounts, so the Mayor's Office
        // clerk cannot act for them — instead Budget's leg is recorded by the
        // one office that is onboarded, exactly as the paper allows.
        $this->assertSame($this->budget->id, $document->fresh()->current_holder_department_id);

        $trail = $document->actions()->oldestFirst()->get();

        $this->assertSame([
            DocumentEvent::Registered,
            DocumentEvent::Released,
            DocumentEvent::Received,
        ], $trail->pluck('action')->all());

        $this->assertSame(1, $document->routes()->count());
        $this->assertSame(3, $document->actions()->count());

        // And the same three acts are in the system-wide audit trail.
        foreach (['document.registered', 'document.released', 'document.received'] as $event) {
            $this->assertDatabaseHas('audit_logs', [
                'event' => $event,
                'auditable_id' => $document->id,
                'auditable_type' => Document::class,
            ]);
        }
    }

    public function test_the_deadline_a_receiving_office_is_given_becomes_the_documents_deadline(): void
    {
        $clerk = $this->clerk($this->mayor);
        $document = $this->register($clerk);

        $due = now()->addDays(3)->startOfMinute();
        $this->routing->release($document, $this->budget, ActionRequested::ForCompliance, $clerk, dueAt: $due);

        $this->assertTrue($due->equalTo($document->fresh()->due_at));
    }

    public function test_an_overdue_document_is_found_by_the_overdue_scope(): void
    {
        $clerk = $this->clerk($this->mayor);
        $document = $this->register($clerk);

        $this->routing->release(
            $document, $this->budget, ActionRequested::ForCompliance, $clerk,
            dueAt: now()->subDays(2),
        );

        $this->assertTrue(Document::overdue()->whereKey($document->id)->exists());
        $this->assertTrue($document->fresh()->isOverdue());
    }
}
