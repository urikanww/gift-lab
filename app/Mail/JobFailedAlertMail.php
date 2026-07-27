<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Staff-facing "a queued job exhausted its retries" dead-letter alert.
 * Queued so a slow SMTP handshake never blocks the process handling
 * Queue::failing(). Counterpart to ProofChangesRequestedMail, but for system
 * health rather than a buyer action - StaffNotifier::jobFailed() is the only
 * place this is sent from (one alert per Queue::failing() call).
 */
class JobFailedAlertMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    // Named $failedQueue, not $queue: Queueable already declares a $queue
    // property (the queue THIS mailable is dispatched on) - reusing the name
    // for "the queue the failed job ran on" is a fatal property redeclaration
    // conflict between the constructor promotion and the trait.
    public function __construct(
        public string $jobName,
        public ?string $uuid,
        public string $connectionName,
        public string $failedQueue,
        public string $exceptionMessage,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Job failed: {$this->jobName} — Gift Lab",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.job-failed-alert',
            with: [
                'jobName' => $this->jobName,
                'uuid' => $this->uuid,
                'connectionName' => $this->connectionName,
                'queue' => $this->failedQueue,
                'exceptionMessage' => $this->exceptionMessage,
            ],
        );
    }
}
