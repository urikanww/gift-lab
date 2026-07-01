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

// onOneServer + withoutOverlapping: the deploy is multi-node (see DEPLOYMENT.md),
// so without a shared cache lock every app node's scheduler would fire this at
// 03:00 simultaneously — racing product writes and duplicate marketplace
// re-checks. Requires a non-array cache driver (redis/database) in production.
Schedule::command('catalogue:resync-scraped')
    ->dailyAt('03:00')
    ->onOneServer()
    ->withoutOverlapping();
