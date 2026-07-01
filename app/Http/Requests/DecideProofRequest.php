<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Proof;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Buyer signs off a proof: approve, or request changes (spec 6.3). Approval is
 * recorded immutably; requesting changes forces a new proof version. Authorized
 * to the buyer of the owning company (or staff acting on their behalf).
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

        if ($user->isStaff()) {
            return true;
        }

        return $user->company_id === $proof->quote->company_id;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', 'in:approve,request_changes'],
            'notes' => ['nullable', 'string', 'max:2000', 'required_if:decision,request_changes'],
        ];
    }
}
