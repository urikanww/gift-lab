<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Proof;
use App\Models\Quote;
use App\Services\ProofCompositeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Buyer-facing "your quote (and proof) are ready" email. Queued so a slow SMTP
 * handshake never blocks the send/approve request.
 *
 * Two shapes, one template:
 *  - plain quote / single-proof resend: pass a bool $hasProof plus one
 *    $proofImageUrl (the send() and resendProof() paths);
 *  - batched proofs-ready round: pass the round's SENT proofs as the second
 *    argument, and the mail lists a thumbnail per item (the sendProofs path).
 */
class QuoteReadyMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * Days a proof-image signed URL stays valid. The buyer may open this email
     * days after it is sent, so the link has to outlive that gap - a short TTL
     * renders a broken image on an object store. Shared by both proof paths
     * (this batched builder and QuoteService::emailQuoteReady) so they cannot
     * drift; 7 is the presigned-URL ceiling on S3/Spaces.
     */
    public const PROOF_IMAGE_URL_TTL_DAYS = 7;

    /** True when the mail carries any proof (single image OR a batched round). */
    public bool $hasProof;

    /**
     * One row per item in a batched proofs-ready round: the product name and a
     * resolved thumbnail link (design-on-product composite, falling back to the
     * raw artwork). Empty for the plain-quote and single-proof variants.
     *
     * @var array<int, array{product_name: string, thumbnail_url: ?string}>
     */
    public array $proofItems = [];

    /**
     * @param  bool|iterable<int, Proof>  $hasProof  A bool for the plain/single-proof
     *                                               variants, or the round's SENT proofs for the batched variant.
     */
    public function __construct(
        public Quote $quote,
        bool|iterable $hasProof = false,
        public ?string $proofImageUrl = null,
        public ?string $greetingName = null,
    ) {
        if (is_iterable($hasProof)) {
            $this->proofItems = $this->buildProofItems($hasProof);
            $this->hasProof = $this->proofItems !== [];
        } else {
            $this->hasProof = $hasProof;
        }
    }

    /**
     * Resolve each proof to {product_name, thumbnail_url}. The thumbnail is the
     * flattened design-on-product composite (a designer proof on its own is a
     * transparent PNG that reads as a logo on white), falling back to the signed
     * artwork URL and finally the raw ref - the same chain, and the same long
     * expiry, QuoteService::emailQuoteReady uses for the single-proof image, so
     * an email opened days later still renders.
     *
     * @param  iterable<int, Proof>  $proofs
     * @return array<int, array{product_name: string, thumbnail_url: ?string}>
     */
    private function buildProofItems(iterable $proofs): array
    {
        $composites = app(ProofCompositeService::class);
        $expiry = now()->addDays(self::PROOF_IMAGE_URL_TTL_DAYS);

        $items = [];
        foreach ($proofs as $proof) {
            $items[] = [
                'product_name' => $proof->lineItem?->product?->name ?? 'Item',
                'thumbnail_url' => $composites->signedCompositeUrl($proof, $expiry)
                    ?? $proof->signedArtworkUrl($expiry)
                    ?? $proof->artworkUrl(),
            ];
        }

        return $items;
    }

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
                'proofItems' => $this->proofItems,
                // /orders/{reference}, not /quotes/{id}: the SPA only routes an
                // order detail by opaque reference, so an id-based link falls
                // through to the catch-all and renders NotFound.
                'quoteUrl' => rtrim((string) config('app.frontend_url', config('app.url')), '/').'/orders/'.$this->quote->reference,
                'greetingName' => $this->greetingName ?? optional($this->quote->creator)->name,
            ],
        );
    }
}
