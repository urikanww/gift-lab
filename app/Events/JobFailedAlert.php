<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Pushed to the staff console when ANY queued job exhausts its retries and
 * lands in the dead-letter table (failed_jobs).
 *
 * Distinct from ProofChangesRequested (a buyer action needing staff attention):
 * this one fires on system health - one alert per Queue::failing() call,
 * regardless of which job class failed. Dispatched exclusively from
 * StaffNotifier::jobFailed(), which is the single place the global
 * Queue::failing() hook (AppServiceProvider::boot()) calls into.
 *
 * ShouldBroadcastNow (not ShouldBroadcast) DELIBERATELY: broadcasting a plain
 * ShouldBroadcast event queues an Illuminate\Broadcasting\BroadcastEvent job.
 * Under QUEUE_CONNECTION=sync (this app's default - see
 * docs/qa/2026-07-03-gift-lab-release-audit.md P2-3), a broadcast transport
 * outage would make THAT job fail too, which would fire Queue::failing again
 * and re-enter StaffNotifier::jobFailed() - broadcasting this same event
 * again, forever. ShouldBroadcastNow broadcasts inline instead (never queued),
 * so a transport failure here is caught directly by Broadcasting::dispatch()
 * in StaffNotifier, exactly like every other broadcast failure, with no
 * queue/Queue::failing involved at all.
 */
class JobFailedAlert implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly string $jobName,
        public readonly ?string $uuid,
        public readonly string $connectionName,
        public readonly string $queue,
        public readonly string $exceptionMessage,
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
        return 'job.failed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'job' => $this->jobName,
            'uuid' => $this->uuid,
            'connection' => $this->connectionName,
            'queue' => $this->queue,
            'exception' => $this->exceptionMessage,
        ];
    }
}
