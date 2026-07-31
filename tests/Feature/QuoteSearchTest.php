<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Quote;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| GET /api/quotes?q=<term>
|--------------------------------------------------------------------------
| Lands before the numeric id stops being displayed, so anyone holding an old
| "#1" from an email or invoice can still find that order. References below are
| set explicitly (and kept digit-free unless a test needs digits) so a LIKE on
| reference cannot accidentally satisfy an id assertion.
*/

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->buyer = User::factory()->create(['company_id' => $this->company->id, 'role' => 'buyer']);
});

it('finds a quote by a partial reference', function (): void {
    $match = Quote::factory()->create(['company_id' => $this->company->id, 'reference' => 'ABC123XYZ']);
    Quote::factory()->create(['company_id' => $this->company->id, 'reference' => 'ZZZQQQWWW']);
    Sanctum::actingAs($this->buyer);

    $this->getJson('/api/quotes?q=C123')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.reference', 'ABC123XYZ')
        ->assertJsonPath('data.0.id', $match->id);
});

it('finds a quote by its exact id', function (): void {
    $match = Quote::factory()->create(['company_id' => $this->company->id, 'reference' => 'AAAAAAAAAA']);
    Quote::factory()->create(['company_id' => $this->company->id, 'reference' => 'BBBBBBBBBB']);
    Sanctum::actingAs($this->buyer);

    $this->getJson('/api/quotes?q='.$match->id)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $match->id);
});

it('matches the id exactly, never as a substring', function (): void {
    // Ids are forced rather than left to autoincrement so the substring
    // relationship (1 inside 10) is deterministic, not lucky. A LIKE on the
    // integer key would match both - and would forfeit the primary key index.
    $match = Quote::factory()->create([
        'id' => 1, 'company_id' => $this->company->id, 'reference' => 'AAAAAAAAAA',
    ]);
    Quote::factory()->create([
        'id' => 10, 'company_id' => $this->company->id, 'reference' => 'BBBBBBBBBB',
    ]);
    Sanctum::actingAs($this->buyer);

    $this->getJson('/api/quotes?q='.$match->id)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', 1);
});

it('accepts a leading # on an id', function (): void {
    // "#42" is how the id has been written everywhere until now, so buyers
    // paste it verbatim rather than stripping the hash themselves.
    $match = Quote::factory()->create(['company_id' => $this->company->id, 'reference' => 'AAAAAAAAAA']);
    Quote::factory()->create(['company_id' => $this->company->id, 'reference' => 'BBBBBBBBBB']);
    Sanctum::actingAs($this->buyer);

    $this->getJson('/api/quotes?q=%23'.$match->id)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $match->id);
});

it('accepts a hash followed by a space', function (): void {
    // "# 42" is as plausible a paste as "#42"; both must reach the id branch
    // rather than falling through to a reference match.
    $match = Quote::factory()->create(['company_id' => $this->company->id, 'reference' => 'AAAAAAAAAA']);
    Quote::factory()->create(['company_id' => $this->company->id, 'reference' => 'BBBBBBBBBB']);
    Sanctum::actingAs($this->buyer);

    $this->getJson('/api/quotes?q=%23%20'.$match->id)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $match->id);
});

it('ignores an array search term instead of erroring', function (): void {
    // ?q[]=abc casts to string as a TypeError; the search box must not 500.
    Quote::factory()->count(2)->create(['company_id' => $this->company->id]);
    Sanctum::actingAs($this->buyer);

    $this->getJson('/api/quotes?q[]=abc')->assertOk()->assertJsonCount(2, 'data');
});

it('treats a LIKE wildcard as a literal character', function (): void {
    // An unescaped % would match every row; escaped, it narrows to references
    // actually containing a percent sign - of which there are none.
    Quote::factory()->count(2)->create(['company_id' => $this->company->id]);
    Sanctum::actingAs($this->buyer);

    $response = $this->getJson('/api/quotes?q=%25')->assertOk();

    expect($response->json('data'))->toBe([]);
});

it('never returns another company’s order when a buyer searches its reference', function (): void {
    // Not a mirror of the id guard below - the two branches differ in risk.
    // `reference` is a plain where(), so un-nesting alone cannot leak through it;
    // only the orWhere on id can escape the scope. What this guards is a future
    // change that PROMOTES reference to an orWhere, which is what happens when a
    // third searchable column lands.
    $other = Company::factory()->create();
    $theirs = Quote::factory()->create(['company_id' => $other->id, 'reference' => 'SECRETREF1']);
    Sanctum::actingAs($this->buyer);

    $response = $this->getJson('/api/quotes?q='.$theirs->reference)->assertOk();

    expect($response->json('data'))->toBe([]);
});

it('never returns another company’s order when a buyer searches its id', function (): void {
    // THE security guard: the id match must not escape the company_id scope.
    // Flat (un-nested) the orWhere would, and a buyer could read any order by
    // guessing an id.
    $other = Company::factory()->create();
    $theirs = Quote::factory()->create(['company_id' => $other->id, 'reference' => 'BBBBBBBBBB']);
    Sanctum::actingAs($this->buyer);

    $response = $this->getJson('/api/quotes?q='.$theirs->id)->assertOk();

    expect($response->json('data'))->toBe([]);
});

it('lets staff search across every company', function (): void {
    $other = Company::factory()->create();
    $theirs = Quote::factory()->create(['company_id' => $other->id, 'reference' => 'FINDME1234']);
    Quote::factory()->create(['company_id' => $this->company->id, 'reference' => 'AAAAAAAAAA']);
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->getJson('/api/quotes?q=FINDME')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $theirs->id);
});

it('returns the full list when no search term is given', function (): void {
    Quote::factory()->count(3)->create(['company_id' => $this->company->id]);
    Sanctum::actingAs($this->buyer);

    $this->getJson('/api/quotes')->assertOk()->assertJsonCount(3, 'data');
});

// P8: the quotes list carries a light per-line preview (product name + image +
// qty) so the reorder rail / order list can show what's in an order.
it('exposes a per-line items_preview on the quotes list', function (): void {
    $product = App\Models\Product::factory()->create([
        'name' => 'Ceramic Mug', 'image_url' => 'http://img.test/mug.jpg',
    ]);
    $quote = Quote::factory()->create(['company_id' => $this->company->id]);
    App\Models\LineItem::factory()->create([
        'quote_id' => $quote->id, 'product_id' => $product->id, 'qty' => 12,
    ]);

    Sanctum::actingAs($this->buyer);

    $this->getJson('/api/quotes')
        ->assertOk()
        ->assertJsonPath('data.0.items_preview.0.name', 'Ceramic Mug')
        ->assertJsonPath('data.0.items_preview.0.image_url', 'http://img.test/mug.jpg')
        ->assertJsonPath('data.0.items_preview.0.qty', 12);
});

it('F9: ?filter=delivered_unpaid returns only closed orders with an outstanding invoice', function (): void {
    $staff = User::factory()->staffAdmin()->create();

    // Delivered (CLOSED) + UNPAID -> included, with the balance exposed.
    $unpaid = Quote::factory()->create(['company_id' => $this->company->id, 'state' => 'CLOSED', 'reference' => 'UNPAIDAAAA']);
    App\Models\Invoice::create(['quote_id' => $unpaid->id, 'po_ref' => 'PO-U', 'payment_state' => 'UNPAID',
        'amount' => 500, 'gst_amount' => 41, 'gst_rate' => 9, 'currency' => 'SGD', 'issued_at' => now()]);

    // Delivered + PARTIAL -> included.
    $partial = Quote::factory()->create(['company_id' => $this->company->id, 'state' => 'CLOSED', 'reference' => 'PARTIALBBB']);
    App\Models\Invoice::create(['quote_id' => $partial->id, 'po_ref' => 'PO-P', 'payment_state' => 'PARTIAL', 'amount_paid' => 100,
        'amount' => 500, 'gst_amount' => 41, 'gst_rate' => 9, 'currency' => 'SGD', 'issued_at' => now()]);

    // Delivered + PAID -> excluded.
    $paid = Quote::factory()->create(['company_id' => $this->company->id, 'state' => 'CLOSED', 'reference' => 'PAIDCCCCCC']);
    App\Models\Invoice::create(['quote_id' => $paid->id, 'po_ref' => 'PO-K', 'payment_state' => 'PAID', 'amount_paid' => 500,
        'amount' => 500, 'gst_amount' => 41, 'gst_rate' => 9, 'currency' => 'SGD', 'issued_at' => now()]);

    // Unpaid but NOT delivered -> excluded.
    $inFlight = Quote::factory()->create(['company_id' => $this->company->id, 'state' => 'CONFIRMED', 'reference' => 'INFLIGHTDD']);
    App\Models\Invoice::create(['quote_id' => $inFlight->id, 'po_ref' => 'PO-F', 'payment_state' => 'UNPAID',
        'amount' => 500, 'gst_amount' => 41, 'gst_rate' => 9, 'currency' => 'SGD', 'issued_at' => now()]);

    Sanctum::actingAs($staff);
    $refs = collect(
        $this->getJson('/api/quotes?filter=delivered_unpaid')->assertOk()->json('data')
    )->pluck('reference')->all();

    expect($refs)->toContain('UNPAIDAAAA', 'PARTIALBBB')
        ->not->toContain('PAIDCCCCCC')
        ->not->toContain('INFLIGHTDD');
});

it('F9: the delivered_unpaid list exposes the outstanding balance per row', function (): void {
    $staff = User::factory()->staffAdmin()->create();
    $q = Quote::factory()->create(['company_id' => $this->company->id, 'state' => 'CLOSED', 'reference' => 'BALANCEEEE']);
    App\Models\Invoice::create(['quote_id' => $q->id, 'po_ref' => 'PO-B', 'payment_state' => 'PARTIAL', 'amount_paid' => 100,
        'amount' => 500, 'gst_amount' => 41, 'gst_rate' => 9, 'currency' => 'SGD', 'issued_at' => now()]);

    Sanctum::actingAs($staff);
    $this->getJson('/api/quotes?filter=delivered_unpaid')
        ->assertOk()
        ->assertJsonPath('data.0.reference', 'BALANCEEEE')
        ->assertJsonPath('data.0.invoice.balance_owed', 400);
});
