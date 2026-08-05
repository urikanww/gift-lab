<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\RegistrationSource;
use App\Enums\UserRole;
use App\Http\Requests\GoogleCompleteRequest;
use App\Models\Company;
use App\Models\User;
use App\Support\AuthUserPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;

/**
 * Google OAuth (Socialite) sign-in / sign-up for corporate BUYERS only.
 *
 * Flow (cross-domain: API host vs. SPA host):
 *   SPA button -> GET /auth/google/redirect  -> Google consent
 *   Google     -> GET /auth/google/callback  -> one of:
 *     - google_id match (buyer)      : sign in, redirect FRONTEND /
 *     - email already exists         : redirect /login?error=google_email_exists
 *                                       (decision 2 - require password first; no
 *                                        auto-link. Also enforces buyers-only: a
 *                                        staff email via Google is refused here.)
 *     - email not verified by Google : redirect /login?error=google_unverified
 *     - brand-new email              : stash the verified profile server-side
 *                                       under an opaque single-use token and
 *                                       redirect /register/google?pending=<token>
 *
 * The two-step sign-up finishes at POST /api/auth/google/complete, which is where
 * the mandatory Company + PDPA consent are collected (a Google profile carries
 * neither). The callback/redirect routes live on the `web` (session) group so
 * Socialite's stateful `state` check and the resulting login session both work;
 * pending/complete are Sanctum-stateful API routes the SPA calls with credentials.
 *
 * Security notes:
 * - No PII in redirect URLs: only an opaque random token travels in the query;
 *   name/email live in server-side cache and are read back over HTTPS by an
 *   authenticated-by-token GET.
 * - Token is single-use (forgotten on consume) with a 10-minute TTL.
 * - Unconfigured provider (no client id) 404s instead of dead-ending at Google.
 */
class GoogleAuthController extends Controller
{
    private const PENDING_TTL_MINUTES = 10;

    /**
     * Which social providers are configured, so the SPA can show/hide buttons
     * instead of rendering a button that dead-ends at a 404. Public + cheap.
     */
    public function providers(): JsonResponse
    {
        return response()->json([
            'google' => filled(config('services.google.client_id')),
        ]);
    }

    /** Kick off the OAuth dance (stateful: Socialite stores `state` in session). */
    public function redirect(): RedirectResponse
    {
        $this->ensureConfigured();

        return Socialite::driver('google')->redirect();
    }

    /** Google returns here. Branch on account state; always end on a SPA redirect. */
    public function callback(): RedirectResponse
    {
        $this->ensureConfigured();

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (\Throwable) {
            // Invalid/expired state, user-denied consent, or a provider error.
            return $this->toFrontend('/login', ['error' => 'google_failed']);
        }

        $email = $googleUser->getEmail();
        $googleId = $googleUser->getId();

        if (blank($email) || blank($googleId)) {
            return $this->toFrontend('/login', ['error' => 'google_failed']);
        }

        if (! $this->emailIsVerified($googleUser->user ?? [])) {
            // Never trust an unverified Google email - it would let someone claim
            // an address they don't control.
            return $this->toFrontend('/login', ['error' => 'google_unverified']);
        }

        // 1) Returning Google buyer.
        $existing = User::where('google_id', $googleId)->first();
        if ($existing !== null) {
            if ($existing->role !== UserRole::Buyer) {
                // Defensive: google_id is only ever set on buyers, but never let
                // Google authenticate into a staff/admin seat (decision 3).
                return $this->toFrontend('/login', ['error' => 'google_not_allowed']);
            }

            $this->signIn($existing);

            return $this->toFrontend('/');
        }

        // 2) Email already belongs to a (password / staff) account. Do NOT link
        //    automatically - require they sign in with their password first.
        if (User::where('email', $email)->exists()) {
            return $this->toFrontend('/login', ['error' => 'google_email_exists']);
        }

        // 3) Brand-new email -> two-step sign-up. Stash the verified profile and
        //    hand the SPA an opaque token to complete company + consent.
        $token = Str::random(48);
        Cache::put($this->pendingKey($token), [
            'google_id' => $googleId,
            'email' => $email,
            'name' => $googleUser->getName() ?: $email,
        ], now()->addMinutes(self::PENDING_TTL_MINUTES));

        return $this->toFrontend('/register/google', ['pending' => $token]);
    }

    /**
     * Read back the pending Google profile so the completion form can show the
     * buyer which name/email they're signing up with. Public (they aren't logged
     * in yet); the opaque token is the credential.
     */
    public function pending(string $token): JsonResponse
    {
        $this->ensureConfigured();

        $data = Cache::get($this->pendingKey($token));
        if ($data === null) {
            return response()->json([
                'message' => 'This sign-up link has expired. Please start again with Google.',
            ], 410);
        }

        return response()->json([
            'name' => $data['name'],
            'email' => $data['email'],
        ]);
    }

    /**
     * Finish sign-up: create the Company + first buyer User atomically (mirroring
     * password registration), consume the token, and sign in.
     */
    public function complete(GoogleCompleteRequest $request): JsonResponse
    {
        $this->ensureConfigured();

        $token = $request->string('token')->toString();
        $data = Cache::get($this->pendingKey($token));

        if ($data === null) {
            throw ValidationException::withMessages([
                'token' => 'This sign-up link has expired. Please start again with Google.',
            ]);
        }

        // Race guard: between callback and complete, the same email/id could have
        // been claimed (concurrent tab, retried callback). Fail closed.
        $taken = User::where('email', $data['email'])
            ->orWhere('google_id', $data['google_id'])
            ->exists();
        if ($taken) {
            Cache::forget($this->pendingKey($token));

            throw ValidationException::withMessages([
                'email' => 'An account for this email already exists. Please sign in instead.',
            ]);
        }

        $user = DB::transaction(function () use ($request, $data): User {
            $company = Company::create([
                'name' => $request->string('company_name')->toString(),
                'registration_no' => $request->input('company_registration_no'),
                'billing_email' => $data['email'],
                'phone' => $request->input('company_phone'),
                'address' => $request->input('company_address'),
                'status' => 'ACTIVE',
            ]);

            $user = new User();
            // forceFill: google_id / consent columns are not mass-assignable, and
            // the profile fields are trusted (server-held, from a verified Google
            // login), not request input.
            $user->forceFill([
                'company_id' => $company->id,
                'name' => $data['name'],
                'email' => $data['email'],
                'google_id' => $data['google_id'],
                'password' => null,
                'role' => UserRole::Buyer->value,
                'email_verified_at' => now(),
                'consented_at' => now(),
                'consent_policy_version' => config('privacy.version'),
                'registration_source' => RegistrationSource::SelfRegistered->value,
            ])->save();

            // Close the created_by loop now that the first user exists.
            $company->created_by = $user->id;
            $company->save();

            return $user;
        });

        Cache::forget($this->pendingKey($token)); // single-use
        $this->signIn($user);

        return response()->json(['user' => AuthUserPayload::for($user)], 201);
    }

    /**
     * Redirect the browser back to the decoupled SPA. Never redirects to a
     * caller-supplied URL - only fixed app paths + an opaque query - so this
     * can't be turned into an open redirect.
     *
     * @param  array<string, string>  $query
     */
    private function toFrontend(string $path, array $query = []): RedirectResponse
    {
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $url = $base.$path;
        if ($query !== []) {
            $url .= '?'.http_build_query($query);
        }

        return redirect()->away($url);
    }

    /** 404 when the provider isn't configured, so the routes fail cleanly. */
    private function ensureConfigured(): void
    {
        if (blank(config('services.google.client_id'))) {
            abort(404);
        }
    }

    /**
     * Google's OIDC userinfo reports verification as `email_verified` (bool); some
     * legacy responses use `verified_email`. Accept the boolean/stringy truthy
     * forms and treat anything else as unverified.
     *
     * @param  array<string, mixed>  $raw
     */
    private function emailIsVerified(array $raw): bool
    {
        $flag = $raw['email_verified'] ?? $raw['verified_email'] ?? false;

        return $flag === true || $flag === 1 || $flag === 'true' || $flag === '1';
    }

    private function signIn(User $user): void
    {
        Auth::guard('web')->login($user);

        $request = request();
        if ($request->hasSession()) {
            // Defeat session fixation, mirroring AuthController::login/register.
            $request->session()->regenerate();
        }
    }

    private function pendingKey(string $token): string
    {
        return 'google_pending:'.$token;
    }
}
