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
 * Staff-facing "an unhandled exception occurred" alert. Queued so a slow SMTP
 * handshake never blocks the request/worker. Sent only from
 * StaffNotifier::unexpectedException(), which throttles by signature.
 */
class ExceptionAlertMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $exceptionClass,
        public string $exceptionMessage,
        public ?string $path,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Unhandled exception: {$this->exceptionClass} — Gift Lab",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.exception-alert',
            with: [
                'exceptionClass' => $this->exceptionClass,
                'exceptionMessage' => $this->exceptionMessage,
                'path' => $this->path,
            ],
        );
    }
}
