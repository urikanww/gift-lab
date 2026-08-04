<?php

declare(strict_types=1);

use App\Events\QuoteStateChanged;
use App\Models\Company;
use App\Models\Product;
use App\Models\Proof;
use App\Models\Quote;
use App\Models\User;
use App\Models\Variant;
use App\Services\QuoteService;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    seedPricing();
    $this->company = Company::factory()->create();
    $this->buyer = User::factory()->create(['company_id' => $this->company->id, 'role' => 'buyer']);
    $this->staff = User::factory()->staffAdmin()->create();
    $this->product = Product::factory()->create(['base_cost' => 10, 'print_method' => 'UV', 'publish_state' => 'PUBLISHED']);
    // CORE products need at least one variant to be quotable (E4 guard).
    Variant::factory()->create(['product_id' => $this->product->id]);
});

it('lets a buyer create a draft quote priced from config', function (): void {
    Sanctum::actingAs($this->buyer);

    $response = $this->postJson('/api/quotes', [
        'company_id' => $this->company->id,
        'line_items' => [
            ['product_id' => $this->product->id, 'variant_id' => null, 'qty' => 3],
        ],
        'shipping_address' => [
            'recipient_name' => 'Rachel Tan',
            'phone' => '+6591234567',
            'line1' => '1 Marina Blvd',
            'postal_code' => '018989',
        ],
        'recipient_consent' => true,
    ]);

    $response->assertCreated()->assertJsonPath('data.state', 'DRAFT');
    expect((float) $response->json('data.total'))->toBeGreaterThan(0.0);
    $this->assertDatabaseCount('line_items', 1);

    // GST is surfaced on the resource as its own itemised amount + the
    // snapshot rate (PricingConfigSeeder seeds tax.gst_pct=9), and it is
    // already folded into `total` (never re-add it client-side).
    expect((float) $response->json('data.gst'))->toBeGreaterThan(0.0);
    expect((float) $response->json('data.gst_rate'))->toBe(9.0);
    $subtotal = (float) $response->json('data.subtotal');
    $delivery = (float) $response->json('data.delivery');
    $gst = (float) $response->json('data.gst');
    $total = (float) $response->json('data.total');
    expect(round($subtotal + $delivery + $gst, 2))->toBe($total);
});

it('rejects a line below the product minimum order quantity', function (): void {
    Sanctum::actingAs($this->buyer);
    $product = Product::factory()->create([
        'base_cost' => 10, 'print_method' => 'UV', 'publish_state' => 'PUBLISHED', 'min_order_qty' => 25,
    ]);
    Variant::factory()->create(['product_id' => $product->id]);

    $this->postJson('/api/quotes', [
        'company_id' => $this->company->id,
        'line_items' => [
            ['product_id' => $product->id, 'variant_id' => null, 'qty' => 10],
        ],
    ])->assertStatus(422)->assertJsonValidationErrors('line_items.0.qty');

    $this->assertDatabaseCount('quotes', 0);
});

it('creates a multi-line quote with batched product/variant lookups', function (): void {
    Sanctum::actingAs($this->buyer);
    $second = Product::factory()->create(['base_cost' => 6, 'print_method' => 'UV', 'publish_state' => 'PUBLISHED']);
    $variant = Variant::factory()->create(['product_id' => $second->id, 'price_delta' => 1.50]);

    $response = $this->postJson('/api/quotes', [
        'company_id' => $this->company->id,
        'line_items' => [
            ['product_id' => $this->product->id, 'variant_id' => null, 'qty' => 3],
            ['product_id' => $second->id, 'variant_id' => $variant->id, 'qty' => 5],
            ['product_id' => $this->product->id, 'variant_id' => null, 'qty' => 2],
        ],
        'shipping_address' => [
            'recipient_name' => 'Rachel Tan',
            'phone' => '+6591234567',
            'line1' => '1 Marina Blvd',
            'postal_code' => '018989',
        ],
        'recipient_consent' => true,
    ]);

    $response->assertCreated()->assertJsonPath('data.state', 'DRAFT');
    $this->assertDatabaseCount('line_items', 3);
    expect((float) $response->json('data.total'))->toBeGreaterThan(0.0)
        // Line 2 resolved its variant from the batched lookup and priced it.
        ->and($response->json('data.line_items.1.variant_id'))->toBe($variant->id)
        ->and((float) $response->json('data.line_items.1.unit_price'))->toBeGreaterThan(0.0);
});

it('still raises model-not-found (404) when a line references a missing product id', function (): void {
    // The FormRequest's exists rule catches this over HTTP; the service must
    // keep the same findOrFail semantics now that lookups are batched.
    app(QuoteService::class)->create($this->company->id, [
        ['product_id' => 999999, 'variant_id' => null, 'qty' => 1, 'customization' => null],
    ], null);
})->throws(ModelNotFoundException::class);

it('persists the need-by deadline and returns it on fetch', function (): void {
    Sanctum::actingAs($this->buyer);
    $neededBy = now()->addWeeks(2)->toDateString();

    $created = $this->postJson('/api/quotes', [
        'company_id' => $this->company->id,
        'needed_by' => $neededBy,
        'line_items' => [
            ['product_id' => $this->product->id, 'variant_id' => null, 'qty' => 2],
        ],
        'shipping_address' => [
            'recipient_name' => 'Rachel Tan',
            'phone' => '+6591234567',
            'line1' => '1 Marina Blvd',
            'postal_code' => '018989',
        ],
        'recipient_consent' => true,
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

/**
 * Run the staff quotes index and return how many queries it took.
 */
function staffQuotesIndexQueryCount(): int
{
    $count = 0;
    DB::listen(function () use (&$count): void {
        $count++;
    });

    test()->getJson('/api/quotes')->assertOk();

    return $count;
}

/**
 * Create a SENT quote with an open (SENT) proof: exactly the row shape that
 * drives ReminderSchedule::awaitingCount() and ProofResource's product_name
 * per proof.
 */
function quoteAwaitingReminderWithProof(int|string $companyId, string $state = 'SENT'): Quote
{
    $quote = Quote::factory()->create([
        'company_id' => $companyId,
        'state' => $state,
        'reminders_sent' => 0,
    ]);
    Proof::factory()->create(['quote_id' => $quote->id, 'state' => 'SENT']);

    return $quote;
}

it('keeps the staff quotes index query count flat as SENT/PROOFING rows are added', function (): void {
    Sanctum::actingAs($this->staff);

    quoteAwaitingReminderWithProof($this->company->id, 'SENT');
    quoteAwaitingReminderWithProof($this->company->id, 'PROOFING');
    $small = staffQuotesIndexQueryCount();

    foreach (range(1, 8) as $ignored) {
        quoteAwaitingReminderWithProof($this->company->id, 'SENT');
        quoteAwaitingReminderWithProof($this->company->id, 'PROOFING');
    }
    $large = staffQuotesIndexQueryCount();

    // Each SENT/ARTWORK_APPROVED row's reminder block used to run a fresh
    // proofs()->where('state','SENT')->count() query (ReminderSchedule::
    // awaitingCount), and each proof's product_name used to re-fetch its
    // lineItem->product - both N+1 without eager-loading proofs.lineItem.product
    // in QuoteController::index(). Without that fix this climbs by roughly one
    // query per added row; fixed, it stays flat regardless of row count.
    expect($large)->toBeLessThanOrEqual($small + 1);
});

it('still surfaces the proof-chase reminder on a PROOFING row in the staff index (not dropped by eager-loading)', function (): void {
    Sanctum::actingAs($this->staff);

    $quote = Quote::factory()->create([
        'company_id' => $this->company->id,
        'state' => 'PROOFING',
        'reminders_sent' => 0,
    ]);
    Proof::factory()->create([
        'quote_id' => $quote->id,
        'state' => 'SENT',
        'created_at' => now()->subDay(),
    ]);

    $response = $this->getJson('/api/quotes')->assertOk();
    $row = collect($response->json('data'))->firstWhere('id', $quote->id);

    // ReminderSchedule::pending() only recognises a PROOFING wait when the
    // proofs relation is loaded - dropping the eager-load silently nulls this
    // out instead of erroring, so assert the actual reminder content.
    expect($row['reminder']['next'])->not->toBeNull()
        ->and($row['reminder']['next']['kind'])->toBe('proof')
        ->and($row['reminder']['next']['awaiting_count'])->toBe(1);
});
