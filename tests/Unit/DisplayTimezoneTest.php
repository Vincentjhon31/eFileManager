<?php

namespace Tests\Unit;

use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Timestamps are stored in UTC and rendered in Philippine time. Receiving
 * timestamps on this system are the record of when a document legally changed
 * hands, so a silent timezone regression would corrupt the audit trail. These
 * tests pin that behaviour down.
 */
class DisplayTimezoneTest extends TestCase
{
    public function test_storage_timezone_stays_utc(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
    }

    public function test_display_timezone_is_philippine_time(): void
    {
        $this->assertSame('Asia/Manila', ph_tz());
    }

    public function test_utc_timestamp_renders_in_philippine_time(): void
    {
        // 00:30 UTC is 08:30 the same morning in Manila (UTC+8, no DST).
        $stored = Carbon::parse('2026-08-06 00:30:00', 'UTC');

        $this->assertSame('06 Aug 2026, 8:30 AM', ph_datetime($stored));
    }

    public function test_render_crossing_midnight_lands_on_the_correct_local_day(): void
    {
        // 20:00 UTC on 05 Aug is already 04:00 on 06 Aug in Manila. Getting
        // this wrong would date-stamp a document to the previous working day.
        $stored = Carbon::parse('2026-08-05 20:00:00', 'UTC');

        $this->assertSame('06 Aug 2026', ph_date($stored));
    }

    public function test_null_timestamps_pass_through(): void
    {
        $this->assertNull(ph_datetime(null));
        $this->assertNull(ph_date(null));
    }
}
