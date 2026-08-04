<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Product;
use App\Models\Quote;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

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

function pdpaLineItems(): array
{
    $product = Product::factory()->create(['publish_state' => 'PUBLISHED']);
    Variant::factory()->create(['product_id' => $product->id]);

    return [['product_id' => $product->id, 'variant_id' => null, 'qty' => 1]];
}

function pdpaShipping(): array
{
    return [
        'recipient_name' => 'Rachel Tan',
        'phone' => '+6591234567',
        'line1' => '1 Marina Blvd',
        'postal_code' => '018989',
    ];
}

it('rejects a buyer checkout without recipient consent', function (): void {
    seedPricing();
    $company = Company::factory()->create();
    Sanctum::actingAs(User::factory()->create(['company_id' => $company->id, 'role' => 'buyer']));

    $this->postJson('/api/quotes', [
        'company_id' => $company->id,
        'line_items' => pdpaLineItems(),
        'shipping_address' => pdpaShipping(),
    ])->assertStatus(422)->assertJsonValidationErrors('recipient_consent');
});

it('stamps recipient consent on a buyer checkout', function (): void {
    seedPricing();
    $company = Company::factory()->create();
    Sanctum::actingAs(User::factory()->create(['company_id' => $company->id, 'role' => 'buyer']));

    $id = $this->postJson('/api/quotes', [
        'company_id' => $company->id,
        'line_items' => pdpaLineItems(),
        'shipping_address' => pdpaShipping(),
        'recipient_consent' => true,
    ])->assertCreated()->json('data.id');

    $quote = Quote::find($id);
    expect($quote->recipient_consent_ack_at)->not->toBeNull()
        ->and($quote->recipient_consent_version)->toBe(config('privacy.version'));
});

it('lets staff create a quote without recipient consent', function (): void {
    seedPricing();
    $company = Company::factory()->create(['address' => '10 Anson Rd']);
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $id = $this->postJson('/api/quotes', [
        'company_id' => $company->id,
        'line_items' => pdpaLineItems(),
    ])->assertCreated()->json('data.id');

    expect(Quote::find($id)->recipient_consent_ack_at)->toBeNull();
});

it('rejects a buyer checkout with recipient_consent explicitly false', function (): void {
    seedPricing();
    $company = Company::factory()->create();
    Sanctum::actingAs(User::factory()->create(['company_id' => $company->id, 'role' => 'buyer']));

    $this->postJson('/api/quotes', [
        'company_id' => $company->id,
        'line_items' => pdpaLineItems(),
        'shipping_address' => pdpaShipping(),
        'recipient_consent' => false,
    ])->assertStatus(422)->assertJsonValidationErrors('recipient_consent');
});

it('does not re-stamp recipient consent when an idempotent checkout is replayed', function (): void {
    seedPricing();
    $company = Company::factory()->create();
    Sanctum::actingAs(User::factory()->create(['company_id' => $company->id, 'role' => 'buyer']));

    $payload = [
        'company_id' => $company->id,
        'line_items' => pdpaLineItems(),
        'shipping_address' => pdpaShipping(),
        'recipient_consent' => true,
        'idempotency_key' => 'checkout-replay-key',
    ];

    $firstId = $this->postJson('/api/quotes', $payload)->assertCreated()->json('data.id');
    $firstAck = Quote::find($firstId)->recipient_consent_ack_at;
    expect($firstAck)->not->toBeNull();

    // Simulate a double-click / network retry: the same payload + key again,
    // some time later, must return the SAME quote with its ORIGINAL consent
    // timestamp - not a fresh now() overwrite.
    $this->travel(5)->minutes();

    $secondId = $this->postJson('/api/quotes', $payload)->assertCreated()->json('data.id');

    expect($secondId)->toBe($firstId);

    $quote = Quote::find($secondId);
    expect($quote->recipient_consent_ack_at->equalTo($firstAck))->toBeTrue();

    expect(Quote::where('company_id', $company->id)->where('idempotency_key', 'checkout-replay-key')->count())->toBe(1);
});
