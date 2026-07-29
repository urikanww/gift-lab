<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Proof;
use App\Services\ProofCompositeService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Proof
 */
class ProofResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'quote_id' => $this->quote_id,
            // Line identity, so the client can group proofs per line and label
            // them by product. The quote-show path eager-loads
            // proofs.lineItem.product to keep product_name off the N+1 path.
            'line_item_id' => $this->line_item_id,
            'product_name' => $this->lineItem?->product?->name,
            // The displayed order identifier. quote_id stays because the realtime
            // stores join incoming broadcasts against on-screen rows by it.
            'quote_reference' => $this->quote?->reference,
            'version' => $this->version,
            'artwork_version_ref' => $this->artwork_version_ref,
            // Resolved viewing link for the artwork, so the client never has to
            // know whether the ref is a stored key or a pasted URL. Null when it
            // is neither (legacy rows hold arbitrary strings), and the UI then
            // shows the raw value as it always did.
            //
            // A proof issued from the buyer's designer artwork is a transparent
            // design-only PNG; viewed raw it reads as a logo floating on white.
            // Prefer the flattened design-on-product composite, but only if it
            // is ALREADY cached - never generate it here. Generating on this
            // (synchronous) page path means an image fetch + GD composite per
            // proof before the page renders; that work belongs to the queued
            // email path. Falls back to the raw artwork until the composite
            // exists (uploaded proofs, non-presigning local disks, or a proof
            // whose email hasn't rendered yet).
            'artwork_url' => app(ProofCompositeService::class)
                ->cachedCompositeUrl($this->resource, now()->addMinutes(30))
                ?? $this->artworkUrl(),
            'state' => $this->state->value,
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'notes' => $this->notes,
            // Buyer's "request changes" reference images, each with a resolved
            // viewing link (null url on a non-presigning local disk). Empty when
            // the buyer attached none.
            'change_attachments' => $this->changeAttachments(),
        ];
    }
}
