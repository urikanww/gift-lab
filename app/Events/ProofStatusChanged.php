<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Proof;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Pushed when a proof is sent, changes-requested, or approved. Buyer sees the
 * sign-off request/result live on the company channel.
 */
class ProofStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly Proof $proof,
        public readonly int $companyId,
    ) {
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("company.{$this->companyId}")];
    }

    public function broadcastAs(): string
    {
        return 'proof.status-changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'proof_id' => $this->proof->id,
            'quote_id' => $this->proof->quote_id,
            'version' => $this->proof->version,
            'state' => $this->proof->state->value,
            'artwork_version_ref' => $this->proof->artwork_version_ref,
        ];
    }
}
