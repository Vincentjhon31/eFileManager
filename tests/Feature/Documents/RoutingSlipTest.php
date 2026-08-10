<?php

namespace Tests\Feature\Documents;

use App\Enums\ActionRequested;
use App\Enums\DocumentStatus;
use App\Enums\ReceiptMethod;
use App\Enums\Role as RoleEnum;
use App\Livewire\Desk\Index;
use App\Livewire\Documents\Track;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use App\Services\DocumentRoutingService;
use App\Services\QrCodeGenerator;
use App\Support\TrackingLink;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * The paper bridge: printed slips, QR codes, and receiving from a phone.
 *
 * This is the part of the system that asks staff to change least — the document
 * still travels by hand and is still signed for in ink — so it is the part most
 * likely to decide whether any of the rest gets used.
 */
class RoutingSlipTest extends TestCase
{
    use RefreshDatabase;

    private DocumentRoutingService $routing;

    private Department $mayor;

    private Department $legal;   // onboarded — can receive digitally

    private Department $budget;  // not onboarded — paper only

    private DocumentType $memo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->routing = app(DocumentRoutingService::class);
        $this->mayor = Department::factory()->onboarded()->create(['code' => 'MO', 'short_name' => "Mayor's Office"]);
        $this->legal = Department::factory()->onboarded()->create(['code' => 'LEGAL', 'short_name' => 'Legal']);
        $this->budget = Department::factory()->create(['code' => 'MBO', 'short_name' => 'Budget']);
        $this->memo = DocumentType::factory()->create(['code' => 'MEMO', 'name' => 'Memorandum']);
    }

    private function clerk(?Department $office = null): User
    {
        $user = User::factory()->inDepartment($office ?? $this->mayor)->create();
        $user->assignRole(RoleEnum::ReceivingClerk->value);

        return $user;
    }

    /** Registered by, and originating from, the clerk's own office. */
    private function registered(User $by): Document
    {
        return $this->routing->register([
            'document_type_id' => $this->memo->id,
            'subject' => 'Budget hearing schedule for FY 2027',
            'origin_department_id' => $by->department_id,
        ], $by);
    }

    /*
    |--------------------------------------------------------------------------
    | The printed slip
    |--------------------------------------------------------------------------
    */

    public function test_the_slip_prints_the_tracking_number_subject_and_a_qr_code(): void
    {
        $clerk = $this->clerk();
        $document = $this->registered($clerk);

        $this->actingAs($clerk)
            ->get(route('documents.slip', $document))
            ->assertOk()
            ->assertSee($document->tracking_no)
            ->assertSee($document->subject)
            ->assertSee('Document Routing Slip')
            ->assertSee('Republic of the Philippines')
            ->assertSee(config('lgu.name'))
            ->assertSee('<svg', false);
    }

    public function test_the_slip_shows_every_recorded_transmittal_and_leaves_room_for_the_next(): void
    {
        $clerk = $this->clerk();
        $document = $this->registered($clerk);

        $this->routing->release($document, $this->budget, ActionRequested::ForApproval, $clerk);
        $this->routing->receive($document, $clerk, ReceiptMethod::Manual, receivedByName: 'Rosalinda Manalo');

        $response = $this->actingAs($clerk)->get(route('documents.slip', $document));

        $response->assertOk()
            ->assertSee('Budget')
            ->assertSee('For approval')
            ->assertSee('Rosalinda Manalo')
            // Paper receipts are labelled as such wherever they appear, on
            // screen and in print.
            ->assertSee('signed on paper');
    }

    public function test_an_unrelated_office_cannot_print_a_slip(): void
    {
        $document = $this->registered($this->clerk());

        $this->actingAs($this->clerk($this->legal))
            ->get(route('documents.slip', $document))
            ->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | The scanned link
    |--------------------------------------------------------------------------
    */

    public function test_the_qr_link_resolves_to_the_tracking_page(): void
    {
        $clerk = $this->clerk();
        $document = $this->registered($clerk);

        $link = TrackingLink::for($document);

        $this->assertStringContainsString('/t/'.$document->tracking_no, $link);
        $this->assertStringContainsString('signature=', $link);

        $this->actingAs($clerk)->get($link)->assertOk()->assertSee($document->subject);
    }

    /**
     * A slip is printed once and carried for years. An absolute signature would
     * break every one of them the day the site moves host or gains a subdomain,
     * so the signature covers the path only.
     */
    public function test_the_link_still_validates_when_the_site_is_reached_by_another_host(): void
    {
        $clerk = $this->clerk();
        $document = $this->registered($clerk);

        $relative = TrackingLink::relative($document);

        $this->actingAs($clerk)
            ->get('http://efilemanager.bongabong.gov.ph'.$relative)
            ->assertOk();
    }

    public function test_an_edited_link_is_refused(): void
    {
        $clerk = $this->clerk();
        $mine = $this->registered($clerk);
        $other = $this->registered($clerk);

        // Swapping the tracking number in a scanned link for a neighbouring one
        // must not work — otherwise the box is a way to discover what exists.
        $tampered = str_replace($mine->tracking_no, $other->tracking_no, TrackingLink::relative($mine));

        $this->actingAs($clerk)->get($tampered)->assertForbidden();
    }

    public function test_an_unsigned_link_is_refused(): void
    {
        $document = $this->registered($this->clerk());

        $this->actingAs($this->clerk())
            ->get('/t/'.$document->tracking_no)
            ->assertForbidden();
    }

    /**
     * The signature proves the link came from us. It is not a credential: the
     * person who scanned it still has to sign in, and the policy still decides
     * what they may see.
     */
    public function test_a_scanned_link_sends_a_signed_out_visitor_to_sign_in_first(): void
    {
        $document = $this->registered($this->clerk());

        $this->get(TrackingLink::for($document))->assertRedirect(route('login'));
    }

    public function test_signing_in_returns_the_visitor_to_the_document_they_scanned(): void
    {
        $clerk = $this->clerk();
        $document = $this->registered($clerk);

        $this->get(TrackingLink::for($document))->assertRedirect(route('login'));

        $this->post('/login', ['email' => $clerk->email, 'password' => 'password'])
            ->assertRedirect(TrackingLink::for($document));
    }

    public function test_a_scanner_from_an_unrelated_office_is_refused_even_with_a_valid_link(): void
    {
        $document = $this->registered($this->clerk());

        $this->actingAs($this->clerk($this->legal))
            ->get(TrackingLink::for($document))
            ->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Receiving from the phone
    |--------------------------------------------------------------------------
    */

    public function test_the_destination_office_receives_in_one_tap(): void
    {
        $sender = $this->clerk();
        $receiver = $this->clerk($this->legal);

        $document = $this->registered($sender);
        $this->routing->release($document, $this->legal, ActionRequested::ForComment, $sender);

        Livewire::actingAs($receiver)
            ->test(Track::class, ['document' => $document])
            ->assertSee('Receive this document')
            ->call('startReceiving')
            ->assertSet('receipt_method', ReceiptMethod::System->value)
            ->call('receive')
            ->assertHasNoErrors();

        $document->refresh();
        $this->assertSame(DocumentStatus::Received, $document->status);
        $this->assertSame($this->legal->id, $document->current_holder_department_id);
        $this->assertSame($receiver->name, $document->latestRoute->received_by_name);
        $this->assertTrue($document->latestRoute->receipt_method->isWitnessed());
    }

    public function test_scanning_for_an_office_that_has_not_onboarded_offers_only_a_paper_receipt(): void
    {
        $clerk = $this->clerk();
        $document = $this->registered($clerk);
        $this->routing->release($document, $this->budget, ActionRequested::ForApproval, $clerk);

        Livewire::actingAs($clerk)
            ->test(Track::class, ['document' => $document])
            ->call('startReceiving')
            ->assertSet('receipt_method', ReceiptMethod::Manual->value)
            ->call('receive')
            ->assertHasErrors('received_by_name');
    }

    public function test_a_paper_receipt_from_the_phone_records_who_signed(): void
    {
        $clerk = $this->clerk();
        $document = $this->registered($clerk);
        $this->routing->release($document, $this->budget, ActionRequested::ForApproval, $clerk);

        Livewire::actingAs($clerk)
            ->test(Track::class, ['document' => $document])
            ->call('startReceiving')
            ->set('received_by_name', 'Rosalinda Manalo')
            ->call('receive')
            ->assertHasNoErrors();

        $leg = $document->fresh()->latestRoute;

        $this->assertSame('Rosalinda Manalo', $leg->received_by_name);
        $this->assertSame($clerk->id, $leg->received_by);
        $this->assertFalse($leg->receipt_method->isWitnessed());
    }

    public function test_a_bystander_who_can_see_the_document_is_shown_its_position_but_no_button(): void
    {
        $sender = $this->clerk();
        $document = $this->registered($sender);
        $this->routing->release($document, $this->legal, ActionRequested::ForComment, $sender);
        $this->routing->receive($document, $this->clerk($this->legal));

        // Legal hold it now. The Mayor's Office can still see it, but there is
        // nothing awaiting receipt, so no action is offered.
        Livewire::actingAs($sender)
            ->test(Track::class, ['document' => $document])
            ->assertDontSee('Receive this document')
            ->assertSee('Legal');
    }

    /*
    |--------------------------------------------------------------------------
    | The counter lookup
    |--------------------------------------------------------------------------
    */

    public function test_a_clerk_finds_a_document_by_typing_its_tracking_number(): void
    {
        $clerk = $this->clerk();
        $document = $this->registered($clerk);
        $this->routing->release($document, $this->budget, ActionRequested::ForApproval, $clerk);

        Livewire::actingAs($clerk)
            ->test(Index::class)
            ->set('lookup', mb_strtolower($document->tracking_no)) // scanners and typists vary
            ->call('findByTrackingNumber')
            ->assertRedirect(route('documents.show', ['document' => $document, 'do' => 'receive']));
    }

    /**
     * A lookup box that answered "that one exists but is not yours" would be a
     * way to map another office's caseload one control number at a time. Both
     * answers have to be the same.
     */
    public function test_a_hidden_tracking_number_is_answered_exactly_like_an_unknown_one(): void
    {
        $clerk = $this->clerk();
        $theirs = $this->registered($this->clerk($this->legal));

        Livewire::actingAs($clerk)
            ->test(Index::class)
            ->set('lookup', 'BGB-MO-2026-01-9999')
            ->call('findByTrackingNumber')
            ->assertHasErrors('lookup')
            ->assertSee('No document here with the number BGB-MO-2026-01-9999');

        Livewire::actingAs($clerk)
            ->test(Index::class)
            ->set('lookup', $theirs->tracking_no)
            ->call('findByTrackingNumber')
            ->assertHasErrors('lookup')
            ->assertSee('No document here with the number '.$theirs->tracking_no)
            ->assertDontSee($theirs->subject);
    }

    public function test_the_receipt_panel_opens_when_arriving_from_the_counter_lookup(): void
    {
        $clerk = $this->clerk();
        $document = $this->registered($clerk);
        $this->routing->release($document, $this->budget, ActionRequested::ForApproval, $clerk);

        $this->actingAs($clerk)
            ->get(route('documents.show', ['document' => $document, 'do' => 'receive']))
            ->assertOk()
            ->assertSee('Record receipt');
    }

    /*
    |--------------------------------------------------------------------------
    | QR rendering
    |--------------------------------------------------------------------------
    */

    /**
     * The other tests prove the SVG appears and that the link works. This one
     * proves they are the same thing — a slip whose square points somewhere
     * else would look perfectly correct on paper and fail in a corridor.
     */
    public function test_the_printed_square_encodes_the_signed_tracking_link(): void
    {
        $clerk = $this->clerk();
        $document = $this->registered($clerk);

        $this->mock(QrCodeGenerator::class, function ($mock) use ($document) {
            $mock->shouldReceive('svg')
                ->once()
                ->with(TrackingLink::for($document), Mockery::any())
                ->andReturn('<svg data-encoded-the-right-thing></svg>');
        });

        $this->actingAs($clerk)
            ->get(route('documents.slip', $document))
            ->assertOk()
            ->assertSee('data-encoded-the-right-thing', false);
    }

    public function test_qr_codes_render_as_inline_svg_with_no_image_extension(): void
    {
        $svg = app(QrCodeGenerator::class)->svg('https://example.test/t/BGB-MO-2026-08-0001');

        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringNotContainsString('<?xml', $svg, 'An XML prolog is invalid inside an HTML page.');
    }
}
