<?php

use App\Console\Commands\SendDeskDigests;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| Driven by a single cron entry on the host:
|
|     * * * * * cd /home/USER/efilemanager && php artisan schedule:run >> /dev/null 2>&1
|
| Times below are Philippine local, stated explicitly because the application
| itself runs in UTC (see config/app.php). Without the timezone argument the
| digest would land at 4pm, which is the wrong end of the working day.
|
*/

Schedule::command(SendDeskDigests::class)
    ->weekdays()
    ->dailyAt('07:30')
    ->timezone('Asia/Manila')
    // The office is small and the mail server is shared. If a run is somehow
    // still going an hour later, do not start a second one on top of it.
    ->withoutOverlapping(60)
    ->onOneServer()
    ->description('Morning desk digest for every employee with something waiting');
