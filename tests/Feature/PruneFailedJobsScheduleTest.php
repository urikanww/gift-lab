<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;

// Task 11: the failed_jobs dead-letter table must be pruned daily so it
// doesn't grow unbounded now every failure is already alerted on (Task 9).

it('schedules queue:prune-failed daily, on one server, without overlapping', function (): void {
    $events = app(Schedule::class)->events();

    $pruneEvent = collect($events)->first(
        fn ($event) => str_contains($event->command ?? '', 'queue:prune-failed')
    );

    expect($pruneEvent)->not->toBeNull();
    expect($pruneEvent->command)->toContain('queue:prune-failed');
    expect($pruneEvent->command)->toContain('--hours=168');
    expect($pruneEvent->expression)->toBe('30 2 * * *');
    expect($pruneEvent->withoutOverlapping)->toBeTrue();
    expect($pruneEvent->onOneServer)->toBeTrue();
});
