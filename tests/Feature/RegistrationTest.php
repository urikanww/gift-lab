<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;

// A2: a brand-new corporate buyer can self-register at Request Quote —
// company + first buyer user created atomically, signed in immediately.

it('registers a new corporate buyer with their company', function (): void {
    // Referer marks the request as coming from the stateful SPA origin, so the
    // Sanctum session (login-on-register) applies exactly as in the browser.
    $response = $this->withHeader('Referer', 'http://localhost')->postJson('/api/register', [
        'name' => 'Jane Tan',
        'email' => 'jane@acme.example',
        'password' => 'super-secret-1',
        'password_confirmation' => 'super-secret-1',
        'company_name' => 'Acme Pte Ltd',
        'company_phone' => '+65 6123 4567',
    ]);

    $response->assertCreated()
        ->assertJsonPath('user.email', 'jane@acme.example')
        ->assertJsonPath('user.role', 'buyer')
        ->assertJsonPath('user.company.name', 'Acme Pte Ltd');

    $user = User::where('email', 'jane@acme.example')->firstOrFail();
    expect($user->company_id)->not->toBeNull()
        ->and($user->company->status)->toBe('ACTIVE')
        ->and($user->company->created_by)->toBe($user->id);

    // Signed in as part of registration (Sanctum stateful session).
    $this->withHeader('Referer', 'http://localhost')
        ->getJson('/api/user')->assertOk()->assertJsonPath('email', 'jane@acme.example');
});

it('rejects a duplicate email registration', function (): void {
    User::factory()->create(['email' => 'taken@acme.example']);

    $this->postJson('/api/register', [
        'name' => 'Jane',
        'email' => 'taken@acme.example',
        'password' => 'super-secret-1',
        'password_confirmation' => 'super-secret-1',
        'company_name' => 'Acme',
    ])->assertStatus(422)->assertJsonValidationErrors('email');

    expect(Company::where('name', 'Acme')->exists())->toBeFalse();
});

// A13: an active session can't register a second company — and the refusal
// explains itself instead of the framework's generic copy.
it('rejects registration from an already-authenticated session with friendly copy', function (): void {
    $existing = User::factory()->create();
    \Laravel\Sanctum\Sanctum::actingAs($existing);

    $this->postJson('/api/register', [
        'name' => 'Jane',
        'email' => 'second@acme.example',
        'password' => 'super-secret-1',
        'password_confirmation' => 'super-secret-1',
        'company_name' => 'Second Co',
    ])->assertForbidden()
        ->assertJsonPath('message', 'You are already signed in. Log out first to register a new company.');
});

it('rejects a mismatched password confirmation', function (): void {
    $this->postJson('/api/register', [
        'name' => 'Jane',
        'email' => 'jane2@acme.example',
        'password' => 'super-secret-1',
        'password_confirmation' => 'different',
        'company_name' => 'Acme',
    ])->assertStatus(422)->assertJsonValidationErrors('password');
});
