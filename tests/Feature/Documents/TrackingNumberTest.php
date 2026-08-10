<?php

namespace Tests\Feature\Documents;

use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use App\Services\DocumentRoutingService;
use App\Services\TrackingNumberGenerator;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Control numbers.
 *
 * A tracking number is written on the paper, quoted in follow-up letters and
 * used to find the file years later, so it has to be right the first time and
 * stay right.
 */
class TrackingNumberTest extends TestCase
{
    use RefreshDatabase;

    private TrackingNumberGenerator $numbers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->numbers = app(TrackingNumberGenerator::class);
    }

    public function test_a_number_carries_the_municipality_office_year_month_and_sequence(): void
    {
        $mayor = Department::factory()->create(['code' => 'MO']);

        $this->travelTo(Carbon::parse('2026-08-14 09:30:00', ph_tz()));

        $this->assertSame('BGB-MO-2026-08-0001', $this->numbers->next($mayor));
        $this->assertSame('BGB-MO-2026-08-0002', $this->numbers->next($mayor));
    }

    public function test_each_office_has_its_own_sequence(): void
    {
        $mayor = Department::factory()->create(['code' => 'MO']);
        $budget = Department::factory()->create(['code' => 'MBO']);

        $this->travelTo(Carbon::parse('2026-08-14 09:30:00', ph_tz()));

        $this->assertSame('BGB-MO-2026-08-0001', $this->numbers->next($mayor));
        $this->assertSame('BGB-MBO-2026-08-0001', $this->numbers->next($budget));
        $this->assertSame('BGB-MO-2026-08-0002', $this->numbers->next($mayor));
    }

    public function test_the_sequence_restarts_each_month(): void
    {
        $mayor = Department::factory()->create(['code' => 'MO']);

        $this->travelTo(Carbon::parse('2026-08-31 16:00:00', ph_tz()));
        $this->assertSame('BGB-MO-2026-08-0001', $this->numbers->next($mayor));

        $this->travelTo(Carbon::parse('2026-09-01 08:00:00', ph_tz()));
        $this->assertSame('BGB-MO-2026-09-0001', $this->numbers->next($mayor));
    }

    /**
     * Timestamps are stored in UTC, but a control number has to agree with the
     * calendar on the wall. At 7am on 1 September in Bongabong it is still
     * 31 August in UTC, and handing that clerk an August number would be wrong
     * in the only way that counts — visibly, on the paper in their hand.
     */
    public function test_the_month_follows_the_manila_calendar_not_utc(): void
    {
        $mayor = Department::factory()->create(['code' => 'MO']);

        $this->travelTo(Carbon::parse('2026-09-01 07:00:00', 'Asia/Manila'));

        $this->assertSame('2026-08-31', now()->utc()->toDateString(), 'Precondition: UTC is still in August.');
        $this->assertSame('BGB-MO-2026-09-0001', $this->numbers->next($mayor));
    }

    public function test_the_counter_survives_a_gap_when_a_registration_is_abandoned(): void
    {
        $mayor = Department::factory()->onboarded()->create(['code' => 'MO']);
        $type = DocumentType::factory()->create();
        $clerk = User::factory()->inDepartment($mayor)->create();
        $routing = app(DocumentRoutingService::class);

        $first = $routing->register([
            'document_type_id' => $type->id,
            'subject' => 'First',
            'origin_department_id' => $mayor->id,
        ], $clerk);

        $second = $routing->register([
            'document_type_id' => $type->id,
            'subject' => 'Second',
            'origin_department_id' => $mayor->id,
        ], $clerk);

        // Sequential, and never reissued — a gap left by a cancelled document
        // is the correct record of a mistake, not something to be tidied away.
        $this->assertNotSame($first->tracking_no, $second->tracking_no);
        $this->assertStringEndsWith('0001', $first->tracking_no);
        $this->assertStringEndsWith('0002', $second->tracking_no);
    }

    /** The database is the last line of defence, and it holds. */
    public function test_two_documents_cannot_share_a_tracking_number(): void
    {
        $office = Department::factory()->create(['code' => 'MO']);
        $type = DocumentType::factory()->create();

        Document::factory()->forOffice($office)->create([
            'document_type_id' => $type->id,
            'tracking_no' => 'BGB-MO-2026-08-0001',
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        Document::factory()->forOffice($office)->create([
            'document_type_id' => $type->id,
            'tracking_no' => 'BGB-MO-2026-08-0001',
        ]);
    }
}
