<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Quote;
use App\Services\OrderTracker;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Pushed to a PUBLIC channel keyed by the opaque order reference so the
 * login-free tracking page updates live (no auth - the reference is the handle).
 * Carries the coarse buyer-facing stage plus the same shipments/item-count
 * detail the HTTP /track payload carries (via OrderTracker, so the shape
 * lives in one place): no pricing, no PII.
 */
class OrderTrackingUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public readonly Quote $quote)
    {
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        // No reference (shouldn't happen post-migration) → no channel.
        if (empty($this->quote->reference)) {
            return [];
        }

        return [new Channel("track.{$this->quote->reference}")];
    }

    public function broadcastAs(): string
    {
        return 'order.tracking-updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $stage = $this->quote->trackingStage();

        // Reuse OrderTracker for shipments/items_* rather than re-deriving them
        // here: without this, the live no-reload path dropped the tracking
        // link and item count whenever an order shipped, only surfacing on a
        // manual refresh (which hits the HTTP payload instead).
        $payload = app(OrderTracker::class)->payload($this->quote);

        return [
            'reference' => $this->quote->reference,
            'stage' => $stage,
            'stage_label' => $this->quote->trackingStageLabel(),
            'cancelled' => $stage === 'CANCELLED',
            'updated_at' => $this->quote->updated_at?->toIso8601String(),
            'shipments' => $payload['shipments'],
            'items_completed' => $payload['items_completed'],
            'items_total' => $payload['items_total'],
        ];
    }
}
