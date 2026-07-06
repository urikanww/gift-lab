<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Self-serve corporate buyer registration (spec 6.1 Stage 0: the account is
 * created at Request Quote, not provisioned ahead of time). Registers the
 * buyer's company and seats the first buyer user against it in one step.
 */
class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Public endpoint; an already-authenticated user has no business here.
        return $this->user() === null;
    }

    protected function failedAuthorization(): never
    {
        // Friendly copy instead of the framework's generic "This action is
        // unauthorized." — the only failure mode is an active session (A13).
        throw new AuthorizationException('You are already signed in. Log out first to register a new company.');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
            'company_name' => ['required', 'string', 'max:255'],
            'company_registration_no' => ['nullable', 'string', 'max:50'],
            'company_phone' => ['nullable', 'string', 'max:50'],
            'company_address' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
