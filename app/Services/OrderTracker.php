<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Quote;

/**
 * Single source of truth for the public, login-free tracking payload. Both the
 * POST /track lookup and the signed GET /track/view deep-link delegate here, so
 * the PII-free contract (status/dates/counts only - no pricing, line detail, or
 * addresses) lives in exactly one place.
 */
final class OrderTracker
{
    /**
     * @return array<string, mixed>
     */
    public function payload(Quote $quote): array
    {
        $labels = Quote::TRACKING_STAGE_LABELS;
        $stage = $quote->trackingStage();

        return [
            'reference' => $quote->tracking_code,
            'stage' => $stage,
            'stage_label' => $quote->trackingStageLabel(),
            'cancelled' => $stage === 'CANCELLED',
            'stages' => array_map(
                static fn (string $c, string $l): array => ['code' => $c, 'label' => $l],
                array_keys($labels),
                array_values($labels),
            ),
            'placed_at' => $quote->created_at?->toIso8601String(),
            'updated_at' => $quote->updated_at?->toIso8601String(),
        ];
    }
}
