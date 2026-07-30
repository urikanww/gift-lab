<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\ProductionJob;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Pushed to the staff console when NinjaVan reports a shipped parcel
 * returned-to-sender or an attempt-failed/pending-reschedule status (see
 * NinjaVanStatusMapper's needsAttention family). The job stays SHIPPED -
 * there is no JobState for "needs staff resolution" - so without this alert
 * a returned parcel would sit silently until someone happened to look.
 *
 * Distinct from ProofChangesRequested (a buyer action) and JobFailedAlert (a
 * system/queue failure): this one is a courier-reported delivery outcome.
 * Same channel as both, though, so every operator gets the live nudge
 * regardless of which order they're looking at.
 */
class ParcelReturned implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly ProductionJob $job,
    ) {
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('staff.queue')];
    }

    public function broadcastAs(): string
    {
        return 'parcel.returned';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'job_id' => $this->job->id,
            'quote_id' => $this->job->quote_id,
            // Displayed identifier; the store keys realtime refreshes off it.
            'quote_reference' => $this->job->quote?->reference,
            'consignment_ref' => $this->job->shipment?->consignment_ref,
            'last_courier_status' => $this->job->shipment?->last_courier_status,
        ];
    }
}
