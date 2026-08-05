<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * Forgot / reset password via Laravel's password broker.
 *
 * Security notes:
 * - Anti-enumeration: sendLink returns the SAME generic message whether or not
 *   the email exists (never reveals account existence). Both routes are
 *   throttled at the route layer (throttle:login).
 * - The reset link carries only the opaque token, not the email (see
 *   AppServiceProvider::boot). The SPA form re-collects the email, so no PII
 *   rides in the URL / referer / browser history.
 * - reset() rotates the remember_token so any "remember me" cookies minted
 *   before the reset are invalidated.
 */
class PasswordResetController extends Controller
{
    public function sendLink(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);

        // Fire-and-forget: the broker emails a link only if the account exists.
        // We ignore the status and always answer the same, so a caller can't
        // probe which emails are registered.
        Password::sendResetLink($request->only('email'));

        return response()->json([
            'message' => 'If that email is registered, we have sent a password reset link.',
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->setRememberToken(Str::random(60));
                $user->save();
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            // Invalid/expired token or unknown email. Keyed on `email` so the SPA
            // shows it inline; message is the broker's translated reason.
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return response()->json([
            'message' => 'Your password has been reset. You can now sign in.',
        ]);
    }
}
