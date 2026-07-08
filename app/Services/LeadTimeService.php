<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ProductClass;
use App\Models\PricingConfig;

/**
 * Deadline-aware delivery estimate for the designer/cart. Every input is read
 * from pricing_configs at call time (spec principle 5). Deliberately CONSERVATIVE
 * and RANGED - an estimate the buyer relies on is a promise, so we pad with a
 * buffer and quote a window, not a single date. Queue depth (the shared floor
 * backlog) feeds the estimate so a busy floor honestly pushes dates out.
 */
final class LeadTimeService
{
    public function __construct(private readonly QueueService $queue)
    {
    }

    /**
     * @param  array<int, ProductClass>  $classes  product classes on the order
     * @return array{earliest: string, latest: string, production_days: int, queue_depth: int, rush_available: bool, rush_earliest: ?string, rush_fee: ?float}
     */
    public function estimate(array $classes): array
    {
        $prodByTrack = (array) PricingConfig::value('lead_time', 'production_days', []);
        $dailyCapacity = max(1, (int) PricingConfig::value('lead_time', 'daily_capacity', 8));
        $bufferDays = (int) PricingConfig::value('lead_time', 'buffer_days', 3);
        $dispatchDays = (int) PricingConfig::value('lead_time', 'dispatch_days', 2);
        $rushShaveDays = (int) PricingConfig::value('lead_time', 'rush_shave_days', 0);
        $rushFee = (float) PricingConfig::value('lead_time', 'rush_fee', 0);

        // Bottleneck track: an order spanning UV + 3D is gated by the slower one.
        $tracks = array_unique(array_map(
            static fn (ProductClass $c): string => $c->track()->value,
            $classes,
        ));

        $baseDays = 0;
        foreach ($tracks as $track) {
            $baseDays = max($baseDays, (int) ($prodByTrack[$track] ?? 0));
        }

        // Backlog already on the floor delays a new order by how many days it
        // takes the floor to clear that queue at its daily capacity.
        $queueDepth = $this->queue->queue()->count();
        $queueDelayDays = (int) ceil($queueDepth / $dailyCapacity);

        $earliestDays = $baseDays + $queueDelayDays + $dispatchDays;
        $latestDays = $earliestDays + $bufferDays;

        $today = now();
        $rushAvailable = $rushShaveDays > 0;
        $rushDays = max(1, $earliestDays - $rushShaveDays);

        return [
            'earliest' => $today->copy()->addDays($earliestDays)->toDateString(),
            'latest' => $today->copy()->addDays($latestDays)->toDateString(),
            'production_days' => $earliestDays,
            'queue_depth' => $queueDepth,
            'rush_available' => $rushAvailable,
            'rush_earliest' => $rushAvailable ? $today->copy()->addDays($rushDays)->toDateString() : null,
            'rush_fee' => $rushAvailable ? round($rushFee, 2) : null,
        ];
    }
}
