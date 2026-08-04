<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Schema;

it('has the pdpa consent columns and a policy version', function (): void {
    expect(Schema::hasColumn('users', 'consented_at'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'consent_policy_version'))->toBeTrue()
        ->and(Schema::hasColumn('quotes', 'recipient_consent_ack_at'))->toBeTrue()
        ->and(Schema::hasColumn('quotes', 'recipient_consent_version'))->toBeTrue()
        ->and(config('privacy.version'))->not->toBeNull();
});

it('rejects registration without consent', function (): void {
    $this->postJson('/api/register', [
        'name' => 'Jane Tan',
        'email' => 'noconsent@acme.example',
        'password' => 'super-secret-1',
        'password_confirmation' => 'super-secret-1',
        'company_name' => 'Acme Pte Ltd',
    ])->assertStatus(422)->assertJsonValidationErrors('consent');
});

it('stamps consented_at and the policy version on register', function (): void {
    $this->withHeader('Referer', 'http://localhost')->postJson('/api/register', [
        'name' => 'Jane Tan',
        'email' => 'consented@acme.example',
        'password' => 'super-secret-1',
        'password_confirmation' => 'super-secret-1',
        'company_name' => 'Acme Pte Ltd',
        'consent' => true,
    ])->assertCreated();

    $user = User::where('email', 'consented@acme.example')->firstOrFail();
    expect($user->consented_at)->not->toBeNull()
        ->and($user->consent_policy_version)->toBe(config('privacy.version'));
});
