<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Proof;
use App\Models\Quote;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Staff-facing "a buyer sent a proof back" email. Queued so a slow SMTP
 * handshake never blocks the buyer's request-changes response. The CTA lands on
 * the staff order page (same /orders/{reference} route the console uses) where
 * they issue the revised proof.
 */
class ProofChangesRequestedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Quote $quote,
        public Proof $proof,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Changes requested on Order {$this->quote->reference} — Gift Lab",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.proof-changes-requested',
            with: [
                'quote' => $this->quote,
                'proof' => $this->proof,
                'notes' => $this->proof->notes,
                'orderUrl' => rtrim((string) config('app.frontend_url', config('app.url')), '/').'/orders/'.$this->quote->reference,
            ],
        );
    }
}
