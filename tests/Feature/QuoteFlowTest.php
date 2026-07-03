<?php

declare(strict_types=1);

use App\Events\QuoteStateChanged;
use App\Models\Company;
use App\Models\Product;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    seedPricing();
    $this->company = Company::factory()->create();
    $this->buyer = User::factory()->create(['company_id' => $this->company->id, 'role' => 'buyer']);
    $this->staff = User::factory()->staffAdmin()->create();
    $this->product = Product::factory()->create(['base_cost' => 10, 'print_method' => 'UV', 'publish_state' => 'PUBLISHED']);
});

it('lets a buyer create a draft quote priced from config', function (): void {
    Sanctum::actingAs($this->buyer);

    $response = $this->postJson('/api/quotes', [
        'company_id' => $this->company->id,
        'line_items' => [
            ['product_id' => $this->product->id, 'variant_id' => null, 'qty' => 3],
        ],
    ]);

    $response->assertCreated()->assertJsonPath('data.state', 'DRAFT');
    expect((float) $response->json('data.total'))->toBeGreaterThan(0.0);
    $this->assertDatabaseCount('line_items', 1);
});

it('persists the need-by deadline and returns it on fetch', function (): void {
    Sanctum::actingAs($this->buyer);
    $neededBy = now()->addWeeks(2)->toDateString();

    $created = $this->postJson('/api/quotes', [
        'company_id' => $this->company->id,
        'needed_by' => $neededBy,
        'line_items' => [
            ['product_id' => $this->product->id, 'variant_id' => null, 'qty' => 2],
        ],
    ])->assertCreated()->assertJsonPath('data.needed_by', $neededBy);

    // Round-trips create -> fetch (not just echoed from the request body).
    $this->getJson("/api/quotes/{$created->json('data.id')}")
        ->assertOk()
        ->assertJsonPath('data.needed_by', $neededBy);
});

it('rejects a need-by date in the past with a 422', function (): void {
    Sanctum::actingAs($this->buyer);

    $this->postJson('/api/quotes', [
        'company_id' => $this->company->id,
        'needed_by' => now()->subDay()->toDateString(),
        'line_items' => [
            ['product_id' => $this->product->id, 'variant_id' => null, 'qty' => 1],
        ],
    ])->assertStatus(422)->assertJsonValidationErrors('needed_by');

    $this->assertDatabaseCount('quotes', 0);
});

it('forbids a buyer creating a quote for another company', function (): void {
    Sanctum::actingAs($this->buyer);
    $other = Company::factory()->create();

    $this->postJson('/api/quotes', [
        'company_id' => $other->id,
        'line_items' => [['product_id' => $this->product->id, 'variant_id' => null, 'qty' => 1]],
    ])->assertStatus(422);
});

it('denies cross-company quote creation at the policy layer', function (): void {
    $other = Company::factory()->create();

    // Defense-in-depth net independent of the FormRequest: a buyer may create a
    // quote for their own company but not another's; staff may create for any.
    expect(Gate::forUser($this->buyer)->allows('create', [Quote::class, $this->company->id]))->toBeTrue()
        ->and(Gate::forUser($this->buyer)->allows('create', [Quote::class, $other->id]))->toBeFalse()
        ->and(Gate::forUser($this->staff)->allows('create', [Quote::class, $other->id]))->toBeTrue();
});

it('broadcasts a state change when a quote is sent', function (): void {
    Event::fake([QuoteStateChanged::class]);
    Sanctum::actingAs($this->staff);
    $quote = Quote::factory()->create(['company_id' => $this->company->id, 'state' => 'DRAFT']);

    $this->postJson("/api/quotes/{$quote->id}/send")->assertOk();

    expect($quote->fresh()->state->value)->toBe('SENT');
    Event::assertDispatched(QuoteStateChanged::class);
});

it('still succeeds on a write when the broadcast transport is down', function (): void {
    // Simulate a Reverb outage: register a broadcaster whose broadcast() throws
    // the transport error (Pusher cURL error 7) that a dead Reverb produces.
    Broadcast::extend('exploding', fn (): Broadcaster => new class implements Broadcaster {
        public function auth($request) {}

        public function validAuthenticationResponse($request, $result) {}

        public function broadcast(array $channels, $event, array $payload = []): void
        {
            throw new RuntimeException('cURL error 7: Failed to connect to localhost port 8080');
        }
    });
    config(['broadcasting.default' => 'exploding']);

    Sanctum::actingAs($this->staff);
    $quote = Quote::factory()->create(['company_id' => $this->company->id, 'state' => 'DRAFT']);

    // The broadcast throws after the DB commit; the helper must swallow it so
    // the committed write still returns success (never a 500).
    $this->postJson("/api/quotes/{$quote->id}/send")->assertOk();

    expect($quote->fresh()->state->value)->toBe('SENT');
});

it('blocks an illegal transition with a friendly 422, leaving state intact', function (): void {
    Sanctum::actingAs($this->staff);
    $quote = Quote::factory()->create(['company_id' => $this->company->id, 'state' => 'READY']);

    // READY cannot be "sent"; the guarded transition throws
    // InvalidStateTransitionException, mapped to a friendly 422 (never a 500).
    $this->postJson("/api/quotes/{$quote->id}/send")->assertStatus(422);
    expect($quote->fresh()->state->value)->toBe('READY');
});

it('includes the company name on quote listings for staff', function (): void {
    $company = Company::factory()->create(['name' => 'Acme Gifts Pte Ltd']);
    Quote::factory()->create(['company_id' => $company->id]);
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->getJson('/api/quotes')
        ->assertOk()
        ->assertJsonPath('data.0.company_name', 'Acme Gifts Pte Ltd');
});

it('survives a soft-deleted company on staff quote listings', function (): void {
    $company = Company::factory()->create(['name' => 'Ghost Co']);
    Quote::factory()->create(['company_id' => $company->id]);
    $company->delete();
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->getJson('/api/quotes')
        ->assertOk()
        ->assertJsonPath('data.0.company_name', null);
});

it('omits the company name on buyer quote listings', function (): void {
    $company = Company::factory()->create();
    Quote::factory()->create(['company_id' => $company->id]);
    Sanctum::actingAs(User::factory()->create(['company_id' => $company->id, 'role' => 'buyer']));

    $response = $this->getJson('/api/quotes')->assertOk();
    expect($response->json('data.0'))->not->toHaveKey('company_name');
});
