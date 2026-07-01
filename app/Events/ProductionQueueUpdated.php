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
 * Pushed whenever a job enters the queue or changes production state, keeping
 * every floor operator's shared-queue view in sync (Reverb-only; no polling).
 */
class ProductionQueueUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  string  $action  queued | started | shipped | closed
     */
    public function __construct(
        public readonly ProductionJob $job,
        public readonly string $action,
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
        return 'production-queue.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'job_id' => $this->job->id,
            'quote_id' => $this->job->quote_id,
            'track' => $this->job->track->value,
            'state' => $this->job->state->value,
            'ready_at' => $this->job->ready_at?->toIso8601String(),
            'qty' => $this->job->qty,
            'action' => $this->action,
        ];
    }
}
