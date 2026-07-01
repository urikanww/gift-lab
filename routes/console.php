<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console routes / scheduled tasks
|--------------------------------------------------------------------------
| The Laravel scheduler (driven by the system cron entry in DEPLOYMENT.md)
| runs the daily scraped-UV catalogue re-sync + drift check (spec 6.4).
*/

Schedule::command('catalogue:resync-scraped')->dailyAt('03:00');
