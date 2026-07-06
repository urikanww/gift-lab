<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

    /**
     * Self-serve corporate buyer registration (spec 6.1 Stage 0). Creates the
     * buyer's company + first buyer user atomically, then signs them in so the
     * request-quote flow continues without a second step.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = DB::transaction(function () use ($request): User {
            $company = Company::create([
                'name' => $request->string('company_name')->toString(),
                'registration_no' => $request->input('company_registration_no'),
                'billing_email' => $request->string('email')->toString(),
                'phone' => $request->input('company_phone'),
                'address' => $request->input('company_address'),
                'status' => 'ACTIVE',
            ]);

            $user = User::create([
                'company_id' => $company->id,
                'name' => $request->string('name')->toString(),
                'email' => $request->string('email')->toString(),
                'password' => $request->string('password')->toString(),
                'role' => UserRole::Buyer->value,
            ]);

            // Close the created_by loop now that the first user exists.
            $company->created_by = $user->id;
            $company->save();

            return $user;
        });

        Auth::login($user);
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return response()->json(['user' => $this->userPayload($user)], 201);
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
