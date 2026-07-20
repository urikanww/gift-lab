<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One hop of a quote's state trail, rendered for the buyer-visible timeline.
 *
 * The actor is exposed as a NAME and nothing else. A buyer can read this
 * endpoint, and the actor on most hops is staff - their email address is not
 * the buyer's to have, and neither is their user id. Adding a field here is
 * adding it to a payload that leaves the tenant.
 *
 * @mixin AuditLog
 */
class QuoteHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // Both sides come out of the audit row's JSON columns rather than
            // being recomputed, so the timeline shows what was recorded at the
            // time even if the state machine's edges change later.
            'from' => $this->old_values['state'] ?? null,
            'to' => $this->new_values['state'] ?? null,
            'changed_at' => $this->created_at?->toIso8601String(),
            // Null for console/queue-driven transitions, which have no actor.
            'actor_name' => $this->user?->name,
        ];
    }
}
