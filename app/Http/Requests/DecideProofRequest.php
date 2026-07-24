<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Proof;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Buyer signs off a proof: approve, or request changes (spec 6.3). Approval is
 * recorded immutably; requesting changes forces a new proof version. Authorized
 * to the owning-company buyer (their own order) or a superadmin acting on their
 * behalf - deliberately NOT any staff_admin, mirroring the UI's superadmin-only
 * "on behalf" gate.
 */
class DecideProofRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $proof = $this->route('proof');

        if ($user === null || ! $proof instanceof Proof) {
            return false;
        }

        return $user->company_id === $proof->quote->company_id || $user->isSuperadmin();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', 'in:approve,request_changes'],
            'notes' => ['nullable', 'string', 'max:2000', 'required_if:decision,request_changes'],
            // Optional reference images the buyer attaches to a change request.
            // Storage keys from /uploads/artwork (never raw files here); capped so
            // a request can't smuggle an unbounded list. Ignored on approve.
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['string', 'max:2048', 'regex:#^artwork/[A-Za-z0-9_\-]+\.[A-Za-z0-9]{1,10}$#'],
        ];
    }
}
