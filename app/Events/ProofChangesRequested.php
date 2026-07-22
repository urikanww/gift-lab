<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Proof;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Pushed to the staff console when a buyer sends a proof back for changes.
 *
 * Distinct from ProofStatusChanged (which fires on the buyer's company channel):
 * this one lands on the shared staff.queue channel so every operator gets the
 * live nudge, whichever order they happen to be looking at. The frontend turns
 * it into a toast and refreshes the Quotes badge.
 */
class ProofChangesRequested implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly Proof $proof,
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
        return 'proof.changes-requested';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'proof_id' => $this->proof->id,
            'quote_id' => $this->proof->quote_id,
            // Displayed identifier; the store keys realtime refreshes off it.
            'quote_reference' => $this->proof->quote?->reference,
            'version' => $this->proof->version,
            // The buyer's reason, so the toast can show WHAT they want changed.
            'notes' => $this->proof->notes,
        ];
    }
}
