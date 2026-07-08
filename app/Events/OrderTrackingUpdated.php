<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Quote;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Pushed to a PUBLIC channel keyed by the opaque tracking code so the
 * login-free tracking page updates live (no auth - the code is the handle).
 * Carries the coarse buyer-facing stage only: no pricing, no PII, mirroring
 * the /track HTTP response.
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
        // No tracking code (shouldn't happen post-migration) → no channel.
        if (empty($this->quote->tracking_code)) {
            return [];
        }

        return [new Channel("track.{$this->quote->tracking_code}")];
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

        return [
            'reference' => $this->quote->tracking_code,
            'stage' => $stage,
            'stage_label' => $this->quote->trackingStageLabel(),
            'cancelled' => $stage === 'CANCELLED',
            'updated_at' => $this->quote->updated_at?->toIso8601String(),
        ];
    }
}
