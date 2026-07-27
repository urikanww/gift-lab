<?php

declare(strict_types=1);

use App\Models\PricingConfig;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

// Bug 1: PricingConfig::$memo is a process-global static. Under php-fpm every
// request is a fresh process so it's harmless, but a long-lived `queue:work`
// worker (deploy/supervisor/giftlab-worker.conf) keeps ONE process alive
// across many jobs. A raw write to the DB (standing in for another process's
// Eloquent write, which forgets the shared cache key itself) leaves this
// worker's memo pinned to the value it read before - until something flushes
// the memo. AppServiceProvider::boot() wires PricingConfig::flushMemo() to
// Illuminate\Queue\Events\JobProcessing so every queued job starts clean.
it('flushes the process memo before each queued job, so a worker sees a config change made by another process', function (): void {
    seedPricing();

    // First read: populates this process's memo AND the shared cache.
    expect(PricingConfig::value('margin', 'default_pct'))->toBe(50);

    // Simulate another process writing the new value: a raw DB update (no
    // Eloquent event fires here) plus the Cache::forget that process's own
    // booted() `saved` listener would have performed. What it can NOT do is
    // reach into THIS process's static $memo - that's the whole bug.
    DB::table('pricing_configs')
        ->where('group', 'margin')->where('key', 'default_pct')
        ->update(['value' => json_encode(75)]);
    Cache::forget('pricing_config:margin:default_pct');

    // Without a memo flush, the stale in-process memo still wins - this is
    // the bug: the shared cache/DB already have 75, but the read below never
    // reaches them.
    expect(PricingConfig::value('margin', 'default_pct'))->toBe(50);

    // Fire the same event Laravel emits immediately before a queued job's
    // handle() runs. This exercises the real AppServiceProvider wiring, not
    // just a direct flushMemo() call. Laravel's own log-context listener also
    // reacts to this event and calls $job->payload(), so the fake job needs
    // to answer that too.
    $job = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
    $job->shouldReceive('payload')->andReturn([]);

    Event::dispatch(new JobProcessing('sync', $job));

    // Next job onward now observes the write.
    expect(PricingConfig::value('margin', 'default_pct'))->toBe(75);
});

// Bug 2: Cache::remember() treats a null callback result as a miss and never
// persists it (Laravel's Repository::get() can't tell "stored null" from
// "nothing stored"), so a genuinely-absent group:key re-runs the DB query on
// every request. PricingConfig::value() now stores a sentinel for an absent
// key so Cache::remember actually persists it.
it('caches a genuinely-absent config key so later requests do not re-query the DB', function (): void {
    DB::enableQueryLog();

    $first = PricingConfig::value('does-not-exist', 'also-missing', 'fallback');

    // Simulate the next php-fpm request: a fresh process has an empty memo,
    // but shares the same cache store.
    PricingConfig::flushMemo();

    $second = PricingConfig::value('does-not-exist', 'also-missing', 'fallback');

    expect($first)->toBe('fallback')
        ->and($second)->toBe('fallback');

    $pricingConfigQueries = collect(DB::getQueryLog())
        ->filter(fn (array $entry): bool => str_contains($entry['query'], 'pricing_configs'));

    expect($pricingConfigQueries)->toHaveCount(1);
});
