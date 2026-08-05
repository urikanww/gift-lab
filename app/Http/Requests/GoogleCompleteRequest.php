<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Second step of Google sign-UP (decision: two-step). Google supplies name +
 * email; this request collects the B2B company details and PDPA consent the
 * account can't be created without. Name/email/password are deliberately absent:
 * name+email come from the verified Google profile held server-side under the
 * `token`, and a Google-only account has no password.
 */
class GoogleCompleteRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Same posture as RegisterRequest: a live session has no business here.
        return $this->user() === null;
    }

    protected function failedAuthorization(): never
    {
        throw new AuthorizationException('You are already signed in. Log out first to finish a new sign-up.');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Opaque, single-use handle to the pending Google profile in cache.
            'token' => ['required', 'string', 'max:64'],
            'company_name' => ['required', 'string', 'max:255'],
            'company_registration_no' => ['nullable', 'string', 'max:50'],
            'company_phone' => ['nullable', 'string', 'max:50'],
            'company_address' => ['nullable', 'string', 'max:1000'],
            // PDPA s.13-14: explicit, recorded consent at the point of collection.
            'consent' => ['required', 'accepted'],
        ];
    }
}
