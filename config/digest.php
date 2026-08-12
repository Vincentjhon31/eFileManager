<?php

/*
|--------------------------------------------------------------------------
| The morning desk digest
|--------------------------------------------------------------------------
|
| Defaults for the weekday round-up (App\Console\Commands\SendDeskDigests).
| All three can be changed by an administrator in Settings → System, which
| overrides the values here at boot; see App\Services\SystemSettings.
|
| The time is Philippine local and is stated in the schedule explicitly,
| because the application itself runs in UTC — without that, a 7:30 setting
| would send at half past three in the afternoon.
|
*/

return [

    // Off stops the digest for everybody, whatever each employee has chosen.
    'enabled' => (bool) env('DIGEST_ENABLED', true),

    // 24-hour HH:MM, Philippine time. Before the office opens, so it is already
    // waiting rather than arriving while somebody is reading yesterday's.
    'time' => env('DIGEST_TIME', '07:30'),

    // How many days ahead a paper must be due before the digest mentions it.
    'due_within' => (int) env('DIGEST_DUE_WITHIN', 2),

];
