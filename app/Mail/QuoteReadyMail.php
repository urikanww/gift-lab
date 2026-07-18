<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Quote;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Buyer-facing "your quote (and proof) are ready" email. Queued so a slow SMTP
 * handshake never blocks the send/approve request. One template, two variants.
 */
class QuoteReadyMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Quote $quote,
        public bool $hasProof,
        public ?string $proofImageUrl,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->hasProof
            ? 'Your quote & proof are ready to review — Gift Lab'
            : 'Your quote is ready to review — Gift Lab';

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.quote-ready',
            with: [
                'quote' => $this->quote,
                'hasProof' => $this->hasProof,
                'proofImageUrl' => $this->proofImageUrl,
                'quoteUrl' => rtrim((string) config('app.frontend_url', config('app.url')), '/').'/quotes/'.$this->quote->id,
                'greetingName' => optional($this->quote->creator)->name,
            ],
        );
    }
}
