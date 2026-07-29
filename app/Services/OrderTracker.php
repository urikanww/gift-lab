<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\LineItemState;
use App\Models\Quote;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

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
            'needed_by' => $quote->needed_by?->toDateString(),
            'items_total' => $this->itemsTotal($quote),
            'items_completed' => $this->itemsCompleted($quote),
            // Product name + quantity per line, so the buyer can see WHAT they
            // ordered on the public tracker. Deliberately name + qty only - no
            // pricing, no PII - to keep the tracker safe to share via a link.
            // Dropped/cancelled lines are omitted (they are no longer part of
            // the order).
            'items' => $quote->lineItems()
                ->with('product')
                ->whereNotIn('line_state', [LineItemState::Dropped->value, LineItemState::Cancelled->value])
                ->get()
                ->map(fn ($line): array => [
                    'name' => $line->product?->name,
                    'qty' => (int) $line->qty,
                ])
                ->values()
                ->all(),
            'shipments' => $this->shipments($quote),
        ];
    }

    /**
     * A permanent, tamper-proof deep link the buyer can bookmark. The signature
     * (keyed by the app secret) IS the second factor, so no email rides in the
     * URL - the payload stays PII-free. Returns a FRONTEND path carrying the same
     * code+signature query the API route validates.
     */
    public function signedFrontendLink(Quote $quote): string
    {
        // absolute:false + signed:relative on the route keeps the signature valid
        // regardless of host, and yields "/api/track/view?code=..&signature=..".
        $apiPath = URL::signedRoute('track.view', ['code' => $quote->tracking_code], null, false);

        return '/track/view?'.Str::after($apiPath, '?');
    }

    private function itemsTotal(Quote $quote): int
    {
        return $quote->lineItems()->count();
    }

    /**
     * A line item counts as completed once its production job is SHIPPED or
     * CLOSED. Counts only - never line detail - so the tracker stays PII-free.
     */
    private function itemsCompleted(Quote $quote): int
    {
        return $quote->lineItems()
            ->whereHas('job', fn ($q) => $q->whereIn('state', [
                \App\Enums\JobState::Shipped->value,
                \App\Enums\JobState::Closed->value,
            ]))
            ->count();
    }

    /**
     * Carrier + consignment ref for each shipped/closed job, with a tracking URL
     * where the carrier offers one, plus the live courier status the NinjaVan
     * webhook keeps updated (in-transit/out-for-delivery/delivered) - the detail
     * a buyer needs between the coarse SHIPPED and DELIVERED stages. PII-free
     * (carrier + parcel ref + status/dates only).
     *
     * @return array<int, array<string, mixed>>
     */
    private function shipments(Quote $quote): array
    {
        return $quote->jobs()
            ->whereIn('state', [
                \App\Enums\JobState::Shipped->value,
                \App\Enums\JobState::Closed->value,
            ])
            ->whereNotNull('consignment_ref')
            ->get()
            ->map(function (\App\Models\ProductionJob $job): array {
                $carrier = $job->carrier;
                $ref = (string) $job->consignment_ref;

                return [
                    'carrier_label' => $carrier?->label(),
                    'tracking_url' => $carrier?->trackingUrl($ref),
                    'ref' => $ref,
                    'status' => $job->last_courier_status,
                    'status_at' => $job->last_courier_status_at?->toIso8601String(),
                    'delivered_at' => $job->delivered_at?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }
}
