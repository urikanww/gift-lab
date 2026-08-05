<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(fn () => RateLimiter::clear('login'));

it('emails a reset link to a registered address', function (): void {
    Notification::fake();
    $user = User::factory()->create(['email' => 'known@acme.example']);

    $this->postJson('/api/forgot-password', ['email' => 'known@acme.example'])
        ->assertOk()
        ->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'reset link'));

    Notification::assertSentTo($user, ResetPassword::class);
});

it('does not reveal whether an email is registered (anti-enumeration)', function (): void {
    Notification::fake();

    // Unknown email: same 200 + same generic message, and nothing is sent.
    $this->postJson('/api/forgot-password', ['email' => 'nobody@acme.example'])
        ->assertOk()
        ->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'reset link'));

    Notification::assertNothingSent();
});

it('builds a token-only reset URL that points at the SPA (no email in the URL)', function (): void {
    Notification::fake();
    $user = User::factory()->create(['email' => 'url@acme.example']);

    $this->postJson('/api/forgot-password', ['email' => 'url@acme.example'])->assertOk();

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
        $url = $notification->toMail($user)->actionUrl;

        return str_contains($url, '/reset-password?token=')
            && ! str_contains($url, 'url@acme.example');
    });
});

it('resets the password with a valid token', function (): void {
    $user = User::factory()->create([
        'email' => 'reset@acme.example',
        'password' => Hash::make('old-password-1'),
    ]);
    $token = Password::createToken($user);

    $this->withHeader('Referer', 'http://localhost')->postJson('/api/reset-password', [
        'token' => $token,
        'email' => 'reset@acme.example',
        'password' => 'brand-new-password-9',
        'password_confirmation' => 'brand-new-password-9',
    ])->assertOk()->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'reset'));

    $user->refresh();
    expect(Hash::check('brand-new-password-9', $user->password))->toBeTrue();
});

it('lets a Google-only (passwordless) account set a password via reset', function (): void {
    // google_id set, password null - reset is how they gain a password login.
    $user = User::factory()->create(['email' => 'g@acme.example', 'password' => null, 'google_id' => 'g-1']);
    $token = Password::createToken($user);

    $this->withHeader('Referer', 'http://localhost')->postJson('/api/reset-password', [
        'token' => $token,
        'email' => 'g@acme.example',
        'password' => 'a-real-password-9',
        'password_confirmation' => 'a-real-password-9',
    ])->assertOk();

    expect(Hash::check('a-real-password-9', $user->refresh()->password))->toBeTrue();
});

it('rejects an invalid or expired reset token', function (): void {
    User::factory()->create(['email' => 'bad@acme.example']);

    $this->postJson('/api/reset-password', [
        'token' => 'not-a-real-token',
        'email' => 'bad@acme.example',
        'password' => 'brand-new-password-9',
        'password_confirmation' => 'brand-new-password-9',
    ])->assertStatus(422)->assertJsonValidationErrors('email');
});

it('rejects a mismatched password confirmation on reset', function (): void {
    $user = User::factory()->create(['email' => 'mm@acme.example']);
    $token = Password::createToken($user);

    $this->postJson('/api/reset-password', [
        'token' => $token,
        'email' => 'mm@acme.example',
        'password' => 'brand-new-password-9',
        'password_confirmation' => 'different-9',
    ])->assertStatus(422)->assertJsonValidationErrors('password');
});
