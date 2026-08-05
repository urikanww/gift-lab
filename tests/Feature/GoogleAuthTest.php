<?php

declare(strict_types=1);

use App\Enums\RegistrationSource;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

// Google OAuth (Socialite) buyer sign-in / sign-up. The provider is mocked so no
// request ever leaves the app; only our branching + persistence is exercised.

beforeEach(function (): void {
    // Provider must look configured or the routes 404 (ensureConfigured).
    config(['services.google.client_id' => 'test-client-id']);
    config(['services.google.client_secret' => 'test-secret']);
    // callback -> throttle:login, complete/pending -> throttle:register (per IP).
    RateLimiter::clear('login');
    RateLimiter::clear('register');
});

/** Build a fake Socialite user (id/name/email + raw payload for email_verified). */
function fakeGoogleUser(string $id, string $email, string $name = 'Jane Tan', bool $verified = true): SocialiteUser
{
    $user = new SocialiteUser();
    $user->id = $id;
    $user->name = $name;
    $user->email = $email;
    $user->user = ['email_verified' => $verified];

    return $user;
}

/** Point the Socialite facade at a provider that returns $googleUser on ->user(). */
function mockSocialite(SocialiteUser $googleUser): void
{
    $provider = Mockery::mock(Provider::class);
    $provider->shouldReceive('user')->andReturn($googleUser);
    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
}

it('reports google enabled/disabled via the providers flag', function (): void {
    $this->getJson('/api/auth/providers')->assertOk()->assertJsonPath('google', true);

    config(['services.google.client_id' => null]);
    $this->getJson('/api/auth/providers')->assertOk()->assertJsonPath('google', false);
});

it('404s the OAuth routes when the provider is unconfigured', function (): void {
    config(['services.google.client_id' => null]);

    $this->get('/auth/google/redirect')->assertNotFound();
    $this->get('/auth/google/callback')->assertNotFound();
});

it('signs in a returning google buyer', function (): void {
    $company = Company::factory()->create();
    $buyer = User::factory()->create([
        'company_id' => $company->id,
        'email' => 'buyer@acme.example',
        'google_id' => 'g-123',
        'role' => UserRole::Buyer->value,
    ]);

    mockSocialite(fakeGoogleUser('g-123', 'buyer@acme.example'));

    $response = $this->get('/auth/google/callback');

    $response->assertRedirect();
    $this->assertStringEndsWith('/', (string) $response->headers->get('Location')); // FRONTEND home
    $this->assertAuthenticatedAs($buyer);
});

it('refuses a google login whose email already has a (password) account - no auto-link', function (): void {
    $company = Company::factory()->create();
    User::factory()->create([
        'company_id' => $company->id,
        'email' => 'existing@acme.example',
        'google_id' => null, // password account
    ]);

    mockSocialite(fakeGoogleUser('g-999', 'existing@acme.example'));

    $response = $this->get('/auth/google/callback');

    $this->assertStringContainsString('/login?error=google_email_exists', (string) $response->headers->get('Location'));
    $this->assertGuest();
});

it('refuses google login for a STAFF email (buyers only)', function (): void {
    User::factory()->create([
        'company_id' => null,
        'email' => 'staff@giftlab.local',
        'role' => UserRole::StaffAdmin->value,
        'google_id' => null,
    ]);

    mockSocialite(fakeGoogleUser('g-staff', 'staff@giftlab.local'));

    $response = $this->get('/auth/google/callback');

    // Email-exists branch catches staff too, so Google never reaches an admin seat.
    $this->assertStringContainsString('/login?error=google_email_exists', (string) $response->headers->get('Location'));
    $this->assertGuest();
});

it('rejects an unverified google email', function (): void {
    mockSocialite(fakeGoogleUser('g-unv', 'new@acme.example', verified: false));

    $response = $this->get('/auth/google/callback');

    $this->assertStringContainsString('/login?error=google_unverified', (string) $response->headers->get('Location'));
    $this->assertGuest();
    expect(User::where('email', 'new@acme.example')->exists())->toBeFalse();
});

it('sends a brand-new google email to the completion form with a pending token', function (): void {
    mockSocialite(fakeGoogleUser('g-new', 'new@acme.example', 'New Buyer'));

    $response = $this->get('/auth/google/callback');

    $location = (string) $response->headers->get('Location');
    $this->assertStringContainsString('/register/google?pending=', $location);

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    $token = $query['pending'] ?? '';
    expect($token)->not->toBe('');

    $pending = Cache::get('google_pending:'.$token);
    expect($pending)->toMatchArray([
        'google_id' => 'g-new',
        'email' => 'new@acme.example',
        'name' => 'New Buyer',
    ]);

    // No account exists yet - creation waits for company + consent.
    $this->assertGuest();
    expect(User::where('email', 'new@acme.example')->exists())->toBeFalse();
});

it('exposes the pending profile for the completion form, then 410s once consumed', function (): void {
    Cache::put('google_pending:tok-1', [
        'google_id' => 'g-1', 'email' => 'p@acme.example', 'name' => 'Pending Person',
    ], now()->addMinutes(10));

    $this->getJson('/api/auth/google/pending/tok-1')
        ->assertOk()
        ->assertJson(['name' => 'Pending Person', 'email' => 'p@acme.example']);

    Cache::forget('google_pending:tok-1');
    $this->getJson('/api/auth/google/pending/tok-1')->assertStatus(410);
});

it('completes sign-up: creates company + buyer, stamps consent, consumes the token, signs in', function (): void {
    Cache::put('google_pending:tok-42', [
        'google_id' => 'g-42', 'email' => 'founder@acme.example', 'name' => 'Founder',
    ], now()->addMinutes(10));

    $response = $this->withHeader('Referer', 'http://localhost')->postJson('/api/auth/google/complete', [
        'token' => 'tok-42',
        'company_name' => 'Acme Pte Ltd',
        'company_phone' => '+65 6123 4567',
        'consent' => true,
    ]);

    $response->assertCreated()
        ->assertJsonPath('user.email', 'founder@acme.example')
        ->assertJsonPath('user.role', 'buyer')
        ->assertJsonPath('user.company.name', 'Acme Pte Ltd');

    $user = User::where('email', 'founder@acme.example')->firstOrFail();
    expect($user->google_id)->toBe('g-42')
        ->and($user->password)->toBeNull()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->consented_at)->not->toBeNull()
        ->and($user->registration_source)->toBe(RegistrationSource::SelfRegistered)
        ->and($user->company->created_by)->toBe($user->id);

    // Single-use: the token is gone.
    expect(Cache::get('google_pending:tok-42'))->toBeNull();
    $this->assertAuthenticatedAs($user);
});

it('rejects completion with an expired / unknown token', function (): void {
    $this->withHeader('Referer', 'http://localhost')->postJson('/api/auth/google/complete', [
        'token' => 'does-not-exist',
        'company_name' => 'Acme Pte Ltd',
        'consent' => true,
    ])->assertStatus(422)->assertJsonValidationErrors('token');

    expect(Company::where('name', 'Acme Pte Ltd')->exists())->toBeFalse();
});

it('requires consent to complete sign-up', function (): void {
    Cache::put('google_pending:tok-nc', [
        'google_id' => 'g-nc', 'email' => 'nc@acme.example', 'name' => 'No Consent',
    ], now()->addMinutes(10));

    $this->withHeader('Referer', 'http://localhost')->postJson('/api/auth/google/complete', [
        'token' => 'tok-nc',
        'company_name' => 'Acme Pte Ltd',
        'consent' => false,
    ])->assertStatus(422)->assertJsonValidationErrors('consent');

    expect(User::where('email', 'nc@acme.example')->exists())->toBeFalse();
});

it('fails closed if the email was claimed between callback and completion', function (): void {
    Cache::put('google_pending:tok-race', [
        'google_id' => 'g-race', 'email' => 'race@acme.example', 'name' => 'Race',
    ], now()->addMinutes(10));

    // Someone (or another tab) registered the same email first.
    User::factory()->create(['email' => 'race@acme.example']);

    $this->withHeader('Referer', 'http://localhost')->postJson('/api/auth/google/complete', [
        'token' => 'tok-race',
        'company_name' => 'Racer Co',
        'consent' => true,
    ])->assertStatus(422)->assertJsonValidationErrors('email');

    expect(Company::where('name', 'Racer Co')->exists())->toBeFalse();
    expect(Cache::get('google_pending:tok-race'))->toBeNull(); // consumed on failure
});
