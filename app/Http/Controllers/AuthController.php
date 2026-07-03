<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Sanctum SPA (stateful cookie) authentication.
 *
 * Security notes:
 * - Uniform "failed" message avoids user-enumeration.
 * - session()->regenerate() on login defeats session fixation.
 * - logout invalidates the session and rotates the CSRF token.
 * - Brute-force throttling is applied on the /login route.
 */
class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->only('email', 'password');

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => __('These credentials do not match our records.'),
            ]);
        }

        $request->session()->regenerate();

        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([], 204);
    }

    public function user(Request $request): JsonResponse
    {
        return response()->json($this->userPayload($request->user()));
    }

    /**
     * Serialize the authenticated user, embedding a minimal company summary
     * (id/name/address) so the storefront can show the buyer where an order
     * ships — read-only, reusing the company's stored address (no per-order
     * address). Only the fields the SPA needs are exposed.
     *
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        $user->loadMissing('company');

        return [
            'id' => $user->id,
            'company_id' => $user->company_id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'company' => $user->company === null ? null : [
                'id' => $user->company->id,
                'name' => $user->company->name,
                'address' => $user->company->address,
            ],
        ];
    }
}
