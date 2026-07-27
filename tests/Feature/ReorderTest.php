<?php

declare(strict_types=1);

use App\Enums\LineItemState;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductionJob;
use App\Models\Proof;
use App\Models\Quote;
use App\Models\ShippingAddress;
use App\Models\User;
use App\Models\Variant;
use Laravel\Sanctum\Sanctum;

/**
 * One-click reorder: clone a past order's cloneable line specs into a fresh
 * DRAFT, re-priced at today's config (GST threads through create()
 * automatically). Nothing else - proofs, invoice, jobs, shipping address,
 * adjustments, amendment log, state, totals - is carried over.
 */
beforeEach(function (): void {
    seedPricing();
    $this->company = Company::factory()->create();
    $this->buyer = User::factory()->create(['company_id' => $this->company->id, 'role' => 'buyer']);
    $this->staff = User::factory()->staffAdmin()->create();
    $this->product = Product::factory()->create(['base_cost' => 10, 'print_method' => 'UV', 'publish_state' => 'PUBLISHED']);
    Variant::factory()->create(['product_id' => $this->product->id]);
});

it('lets a buyer reorder their own past order into a fresh re-priced draft', function (): void {
    $source = Quote::factory()->create([
        'company_id' => $this->company->id,
        'state' => 'CLOSED',
        'subtotal' => 999,
        'total' => 1234,
        'adjustments' => [['label' => 'Loyalty discount', 'amount' => -10]],
    ]);
    $line = $source->lineItems()->create([
        'product_id' => $this->product->id,
        'variant_id' => null,
        'qty' => 4,
        'unit_price' => 12,
        'currency' => 'SGD',
        'customization' => ['logo_size' => 'M', 'text' => 'Happy Birthday'],
        'line_state' => LineItemState::Ready->value,
    ]);

    // Prove nothing beyond line specs survives the clone.
    Proof::factory()->for($source)->create(['line_item_id' => $line->id]);
    Invoice::create([
        'quote_id' => $source->id,
        'po_ref' => 'PO-1',
        'payment_state' => 'PAID',
        'amount' => 1234,
        'gst_amount' => 100,
        'gst_rate' => 9,
        'currency' => 'SGD',
    ]);
    ProductionJob::factory()->for($source)->create();
    ShippingAddress::create([
        'quote_id' => $source->id,
        'recipient_name' => 'Rachel Tan',
        'phone' => '+6591234567',
        'line1' => '1 Marina Blvd',
        'postal_code' => '018989',
        'country' => 'SG',
    ]);

    Sanctum::actingAs($this->buyer);

    $response = $this->postJson("/api/quotes/{$source->id}/reorder");

    $response->assertCreated()->assertJsonPath('data.state', 'DRAFT');

    $newId = $response->json('data.id');
    expect($newId)->not->toBe($source->id);
    expect($response->json('data.reference'))->not->toBe($source->reference);

    $this->assertDatabaseCount('line_items', 2); // source's 1 + the clone's 1
    $newQuote = Quote::find($newId);
    expect($newQuote->lineItems)->toHaveCount(1);

    $clonedLine = $newQuote->lineItems->first();
    expect($clonedLine->product_id)->toBe($this->product->id);
    expect($clonedLine->variant_id)->toBeNull();
    expect($clonedLine->qty)->toBe(4);
    expect($clonedLine->customization)->toBe(['logo_size' => 'M', 'text' => 'Happy Birthday']);

    // Re-priced, not copied: the new draft's totals come from today's config,
    // not the (unrelated) numbers stashed on the source above.
    expect((float) $response->json('data.total'))->toBeGreaterThan(0.0);
    expect((float) $response->json('data.gst'))->toBeGreaterThan(0.0);
    expect($response->json('data.adjustments'))->toBe([]);

    // Nothing else cloned.
    expect(Proof::where('quote_id', $newId)->count())->toBe(0);
    expect(Invoice::where('quote_id', $newId)->count())->toBe(0);
    expect(ProductionJob::where('quote_id', $newId)->count())->toBe(0);
    expect(ShippingAddress::where('quote_id', $newId)->count())->toBe(0);
});

it('excludes dropped and cancelled lines from the clone', function (): void {
    $source = Quote::factory()->create(['company_id' => $this->company->id, 'state' => 'CLOSED']);
    $source->lineItems()->create([
        'product_id' => $this->product->id, 'variant_id' => null, 'qty' => 2,
        'unit_price' => 5, 'currency' => 'SGD', 'customization' => null,
        'line_state' => LineItemState::Ready->value,
    ]);
    $source->lineItems()->create([
        'product_id' => $this->product->id, 'variant_id' => null, 'qty' => 3,
        'unit_price' => 5, 'currency' => 'SGD', 'customization' => null,
        'line_state' => LineItemState::Dropped->value,
    ]);
    $source->lineItems()->create([
        'product_id' => $this->product->id, 'variant_id' => null, 'qty' => 1,
        'unit_price' => 5, 'currency' => 'SGD', 'customization' => null,
        'line_state' => LineItemState::Cancelled->value,
    ]);

    Sanctum::actingAs($this->buyer);

    $response = $this->postJson("/api/quotes/{$source->id}/reorder");

    $response->assertCreated();
    $newQuote = Quote::find($response->json('data.id'));
    expect($newQuote->lineItems)->toHaveCount(1);
    expect($newQuote->lineItems->first()->qty)->toBe(2);
});

it('excludes a line whose product was soft-deleted since the original order, instead of 404ing the whole reorder', function (): void {
    $goneProduct = Product::factory()->create(['base_cost' => 8, 'print_method' => 'UV', 'publish_state' => 'PUBLISHED']);
    $source = Quote::factory()->create(['company_id' => $this->company->id, 'state' => 'CLOSED']);
    $source->lineItems()->create([
        'product_id' => $this->product->id, 'variant_id' => null, 'qty' => 2,
        'unit_price' => 5, 'currency' => 'SGD', 'customization' => null,
        'line_state' => LineItemState::Ready->value,
    ]);
    $source->lineItems()->create([
        'product_id' => $goneProduct->id, 'variant_id' => null, 'qty' => 1,
        'unit_price' => 5, 'currency' => 'SGD', 'customization' => null,
        'line_state' => LineItemState::Ready->value,
    ]);
    $goneProduct->delete();

    Sanctum::actingAs($this->buyer);

    $response = $this->postJson("/api/quotes/{$source->id}/reorder");

    $response->assertCreated();
    $newQuote = Quote::find($response->json('data.id'));
    expect($newQuote->lineItems)->toHaveCount(1);
    expect($newQuote->lineItems->first()->product_id)->toBe($this->product->id);
});

it('422s reordering an order whose lines are all dropped or cancelled', function (): void {
    $source = Quote::factory()->create(['company_id' => $this->company->id, 'state' => 'CANCELLED']);
    $source->lineItems()->create([
        'product_id' => $this->product->id, 'variant_id' => null, 'qty' => 2,
        'unit_price' => 5, 'currency' => 'SGD', 'customization' => null,
        'line_state' => LineItemState::Dropped->value,
    ]);
    $source->lineItems()->create([
        'product_id' => $this->product->id, 'variant_id' => null, 'qty' => 1,
        'unit_price' => 5, 'currency' => 'SGD', 'customization' => null,
        'line_state' => LineItemState::Cancelled->value,
    ]);

    Sanctum::actingAs($this->buyer);

    $this->postJson("/api/quotes/{$source->id}/reorder")->assertStatus(422);
    $this->assertDatabaseCount('quotes', 1);
});

it('forbids a buyer from reordering another company\'s order', function (): void {
    $otherCompany = Company::factory()->create();
    $otherBuyer = User::factory()->create(['company_id' => $otherCompany->id, 'role' => 'buyer']);
    $source = Quote::factory()->create(['company_id' => $this->company->id, 'state' => 'CLOSED']);
    $source->lineItems()->create([
        'product_id' => $this->product->id, 'variant_id' => null, 'qty' => 2,
        'unit_price' => 5, 'currency' => 'SGD', 'customization' => null,
        'line_state' => LineItemState::Ready->value,
    ]);

    Sanctum::actingAs($otherBuyer);

    $this->postJson("/api/quotes/{$source->id}/reorder")->assertForbidden();
});

it('lets staff reorder any company\'s order', function (): void {
    $source = Quote::factory()->create(['company_id' => $this->company->id, 'state' => 'CLOSED']);
    $source->lineItems()->create([
        'product_id' => $this->product->id, 'variant_id' => null, 'qty' => 2,
        'unit_price' => 5, 'currency' => 'SGD', 'customization' => null,
        'line_state' => LineItemState::Ready->value,
    ]);

    Sanctum::actingAs($this->staff);

    $this->postJson("/api/quotes/{$source->id}/reorder")->assertCreated();
});

it('401s a guest attempting to reorder', function (): void {
    $source = Quote::factory()->create(['company_id' => $this->company->id, 'state' => 'CLOSED']);
    $source->lineItems()->create([
        'product_id' => $this->product->id, 'variant_id' => null, 'qty' => 2,
        'unit_price' => 5, 'currency' => 'SGD', 'customization' => null,
        'line_state' => LineItemState::Ready->value,
    ]);

    $this->postJson("/api/quotes/{$source->id}/reorder")->assertUnauthorized();
});
