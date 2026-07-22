<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Proof;
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
            // The displayed order identifier. quote_id stays because the realtime
            // stores join incoming broadcasts against on-screen rows by it.
            'quote_reference' => $this->quote?->reference,
            'version' => $this->version,
            'artwork_version_ref' => $this->artwork_version_ref,
            // Resolved viewing link for the artwork, so the client never has to
            // know whether the ref is a stored key or a pasted URL. Null when it
            // is neither (legacy rows hold arbitrary strings), and the UI then
            // shows the raw value as it always did.
            'artwork_url' => $this->artworkUrl(),
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
