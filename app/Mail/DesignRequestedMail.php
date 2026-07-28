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
 * Staff-facing "a buyer asked us to design this" email. Queued so a slow SMTP
 * handshake never blocks the buyer's checkout. The CTA lands on the staff order
 * page (same /orders/{reference} route the console uses) where they review the
 * buyer's reference images + notes and stage the first proof.
 */
class DesignRequestedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, array{product_name: string, qty: int}>  $lines
     */
    public function __construct(
        public Quote $quote,
        public array $lines,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "New design request on Order {$this->quote->reference} — Gift Lab",
        );
    }

    public function content(): Content
    {
        $rows = '';
        foreach ($this->lines as $line) {
            $rows .= '
                <tr>
                    <td style="padding:14px 0; border-top:1px solid #f0f0f6; font-family:Helvetica,Arial,sans-serif; font-size:14px; color:#14141a;">'
                        .e($line['product_name']).'</td>
                    <td align="right" style="padding:14px 0; border-top:1px solid #f0f0f6; font-family:Helvetica,Arial,sans-serif; font-size:14px; color:#8a8a99;">Qty '
                        .e((string) $line['qty']).'</td>
                </tr>';
        }

        return new Content(
            view: 'mail.design-requested',
            with: [
                'quote' => $this->quote,
                'lineCount' => count($this->lines),
                'rows' => $rows,
                'orderUrl' => rtrim((string) config('app.frontend_url', config('app.url')), '/').'/orders/'.$this->quote->reference,
            ],
        );
    }
}
