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
// 03:00 simultaneously - racing product writes and duplicate marketplace
// re-checks. Requires a non-array cache driver (redis/database) in production.
Schedule::command('catalogue:resync-scraped')
    ->dailyAt('03:00')
    ->onOneServer()
    ->withoutOverlapping();

// Daily MODEL_3D licence re-check (creator can re-licence/delete upstream -
// drifted items are pulled from public for re-review).
Schedule::command('catalogue:resync-3d')
    ->dailyAt('03:30')
    ->onOneServer()
    ->withoutOverlapping();

// Nightly discovery sweep: new licence-cleared 3D models flow in from the
// configured keyword list; every item still passes the full publish gate.
Schedule::command('catalogue:discover-3d')
    ->dailyAt('04:00')
    ->onOneServer()
    ->withoutOverlapping();

// Slicer sweep after discovery: measures real grams/print-minutes for any
// unverified 3D item (no-op until SLICER_BINARY is configured).
Schedule::command('catalogue:slice-pending')
    ->dailyAt('04:30')
    ->onOneServer()
    ->withoutOverlapping();

// Daily orphan sweep for the public, account-free artwork upload: deletes anon
// uploads no quote/proof/job ever referenced and older than the grace window,
// so abandoned-designer files can't accumulate on the private artwork disk
// (P2-2). onOneServer so the multi-node deploy prunes exactly once.
Schedule::command('artwork:prune-orphans')
    ->dailyAt('05:00')
    ->onOneServer()
    ->withoutOverlapping();
