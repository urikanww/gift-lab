<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\ProductionJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Staff-facing "NinjaVan reported this parcel returned/failed" email. Queued
 * so a slow SMTP handshake never blocks the inbound webhook response. The CTA
 * lands on the staff order page where they resolve the job (close/reship/
 * cancel+credit) via ProductionQueueController::resolveReturn.
 */
class ParcelReturnedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public ProductionJob $job,
    ) {}

    public function envelope(): Envelope
    {
        $reference = $this->job->quote?->reference ?? "job #{$this->job->id}";

        return new Envelope(
            subject: "Parcel returned/failed on Order {$reference} — Gift Lab",
        );
    }

    public function content(): Content
    {
        $quote = $this->job->quote;

        return new Content(
            view: 'mail.parcel-returned',
            with: [
                'job' => $this->job,
                'quote' => $quote,
                'lastCourierStatus' => $this->job->shipment?->last_courier_status,
                'consignmentRef' => $this->job->shipment?->consignment_ref,
                'orderUrl' => $quote !== null
                    ? rtrim((string) config('app.frontend_url', config('app.url')), '/').'/orders/'.$quote->reference
                    : null,
            ],
        );
    }
}
