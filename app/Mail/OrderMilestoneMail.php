<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\OrderMilestone;
use App\Models\Quote;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * One template for every order milestone; the copy comes from the enum.
 *
 * Queued, so a slow SMTP handshake never holds up the state change that
 * prompted it.
 */
class OrderMilestoneMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Quote $quote,
        public OrderMilestone $milestone,
    ) {}

    public function envelope(): Envelope
    {
        // Reply-to is a monitored address, never no-reply: buyers reply to these
        // - especially to a chase - and a reply that vanishes is worse than not
        // having sent the chase at all.
        $support = (string) config('mail.support_address', '');

        return new Envelope(
            subject: $this->milestone->subject($this->quote->reference),
            replyTo: $support !== '' ? [new Address($support)] : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.order-milestone',
            with: [
                'quote' => $this->quote,
                'heading' => $this->milestone->heading(),
                'body' => $this->milestone->body(),
                'ctaLabel' => $this->milestone->ctaLabel(),
                // /orders/{reference}, not /quotes/{id}: the SPA only routes an
                // order detail by opaque reference.
                'quoteUrl' => rtrim((string) config('app.frontend_url', config('app.url')), '/')
                    .'/orders/'.$this->quote->reference,
                'greetingName' => optional($this->quote->creator)->name,
            ],
        );
    }
}
