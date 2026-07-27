<?php

declare(strict_types=1);

use App\Services\Courier\NinjaVanTrackingNumber;

/**
 * forJob must be keyed on the job id (not just the quote id): a multi-job
 * quote (one UV job + one job per 3D line, see QueueService::buildJobsForQuote)
 * books one NinjaVan order per job, and NinjaVan rejects a second order under
 * a requested_tracking_number it has already seen - so every job of the same
 * quote must resolve to a distinct value.
 */
it('is distinct per job even for the same quote', function (): void {
    $a = NinjaVanTrackingNumber::forJob(quoteId: 501, jobId: 9001);
    $b = NinjaVanTrackingNumber::forJob(quoteId: 501, jobId: 9002);

    expect($a)->not->toBe($b);
});

it('is deterministic for the same quote/job pair (retry-safe idempotency key)', function (): void {
    $first = NinjaVanTrackingNumber::forJob(quoteId: 501, jobId: 9001);
    $second = NinjaVanTrackingNumber::forJob(quoteId: 501, jobId: 9001);

    expect($first)->toBe($second);
});

it('never exceeds NinjaVan\'s 9-character requested_tracking_number limit', function (): void {
    foreach ([1, 42, 9001, 123456789, 999999999999] as $jobId) {
        expect(strlen(NinjaVanTrackingNumber::forJob(quoteId: 501, jobId: $jobId)))->toBeLessThanOrEqual(9);
    }
});

it('stays distinct across many jobs of the same quote, including large ids that hit the hash fallback', function (): void {
    // 36^7 (~7.8e10) is the point at which prefix + base36(jobId) stops
    // fitting the 9-char budget and forJob falls back to the hashed slice -
    // pick ids comfortably past that so this actually exercises the fallback.
    $refs = collect(range(1, 50))
        ->map(fn (int $i): string => NinjaVanTrackingNumber::forJob(quoteId: 501, jobId: 100_000_000_000 + $i));

    expect($refs->unique())->toHaveCount(50);
});
