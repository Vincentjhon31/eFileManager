<?php

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Display helpers
|--------------------------------------------------------------------------
|
| Timestamps are stored in UTC (see config/app.php). Everything shown to a
| user must be converted to Philippine time first. Use these helpers rather
| than calling ->format() directly on a model attribute, otherwise the value
| renders in UTC and a document received at 8:30 AM shows as 12:30 AM.
|
*/

if (! function_exists('ph_tz')) {
    /**
     * The timezone all user-facing timestamps are rendered in.
     */
    function ph_tz(): string
    {
        return config('app.display_timezone', 'Asia/Manila');
    }
}

if (! function_exists('ph_datetime')) {
    /**
     * Render a timestamp in Philippine time, e.g. "06 Aug 2026, 8:30 AM".
     */
    function ph_datetime(CarbonInterface|string|null $value, string $format = 'd M Y, g:i A'): ?string
    {
        if ($value === null) {
            return null;
        }

        return Carbon::parse($value)->timezone(ph_tz())->format($format);
    }
}

if (! function_exists('ph_date')) {
    /**
     * Render a date in Philippine time, e.g. "06 Aug 2026".
     */
    function ph_date(CarbonInterface|string|null $value, string $format = 'd M Y'): ?string
    {
        return ph_datetime($value, $format);
    }
}

if (! function_exists('ph_now')) {
    /**
     * "Now" in Philippine time. Use only for display or for deriving a local
     * calendar date (such as a tracking-number month segment) — never to write
     * a timestamp to the database.
     */
    function ph_now(): Carbon
    {
        return Carbon::now(ph_tz());
    }
}
