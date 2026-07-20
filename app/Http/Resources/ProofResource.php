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
            'state' => $this->state->value,
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'notes' => $this->notes,
        ];
    }
}
