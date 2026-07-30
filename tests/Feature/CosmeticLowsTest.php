<?php

declare(strict_types=1);

use App\Enums\Carrier;
use App\Enums\JobState;
use App\Enums\LineItemState;
use App\Models\Company;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\ProductionJob;
use App\Models\Proof;
use App\Models\Quote;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Courier\NinjaVanStatusMapper;
use App\Services\OrderTracker;
use App\Services\QuoteService;
use Laravel\Sanctum\Sanctum;

// L2: accept() only applies to a quote awaiting price agreement; any other
// state gets a clean domain error, not a raw state-machine 422.
it('refuses to accept a quote that is not awaiting price acceptance (L2)', function (): void {
    $quote = Quote::factory()->create(['state' => 'CONFIRMED']);

    expect(fn () => app(QuoteService::class)->accept($quote))
        ->toThrow(App\Exceptions\DomainRuleException::class, 'This order is not awaiting price acceptance.');

    // accepted_at was never stamped (guard runs before the write).
    expect($quote->fresh()->accepted_at)->toBeNull();
});

it('accepts a SENT quote normally (L2 does not over-block)', function (): void {
    $quote = Quote::factory()->create(['state' => 'SENT']);
    $product = Product::factory()->create(['class' => 'CORE']);
    // A customized line still needs a proof, so accept rests at ACCEPTED rather
    // than auto-advancing as a plain-stock order would.
    LineItem::factory()->create([
        'quote_id' => $quote->id, 'product_id' => $product->id,
        'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/x.png'],
    ]);

    $result = app(QuoteService::class)->accept($quote);

    expect($result->state->value)->toBe('ACCEPTED');
});

// L19: a whitespace-only change note is rejected on a direct API call, matching
// the UI's own block.
it('rejects a whitespace-only proof change note (L19)', function (): void {
    $company = Company::factory()->create();
    $buyer = User::factory()->create(['company_id' => $company->id, 'role' => 'buyer']);
    $product = Product::factory()->create(['class' => 'CORE']);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'state' => 'PROOFING']);
    $line = LineItem::factory()->create(['quote_id' => $quote->id, 'product_id' => $product->id]);
    $proof = Proof::factory()->forLine($line)->create(['state' => 'SENT']);

    Sanctum::actingAs($buyer);

    $this->postJson("/api/proofs/{$proof->id}/decide", [
        'decision' => 'request_changes',
        'notes' => '    ',
    ])->assertStatus(422)->assertJsonValidationErrors('notes');
});

// L14: a returned-flagged parcel that still sits SHIPPED must not count as a
// completed/shipped item on the public tracker.
it('does not count a returned-flagged shipped parcel as completed (L14)', function (): void {
    $company = Company::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $company->id, 'state' => 'READY']);
    $product = Product::factory()->create(['class' => 'CORE']);

    // Courier fields live on each parcel's shipment now (Stage 2a).
    $shippedJob = ProductionJob::factory()->create([
        'quote_id' => $quote->id, 'state' => JobState::Shipped->value,
    ]);
    $shippedJob->shipment()->associate(Shipment::factory()->create([
        'quote_id' => $quote->id, 'consignment_ref' => 'NVOK1', 'carrier' => Carrier::NinjaVan->value,
        'last_courier_status' => 'Out for Delivery',
    ]))->save();
    $returnedJob = ProductionJob::factory()->create([
        'quote_id' => $quote->id, 'state' => JobState::Shipped->value,
    ]);
    $returnedJob->shipment()->associate(Shipment::factory()->create([
        'quote_id' => $quote->id, 'consignment_ref' => 'NVRET1', 'carrier' => Carrier::NinjaVan->value,
        'last_courier_status' => NinjaVanStatusMapper::LABEL_RETURNED,
    ]))->save();
    LineItem::factory()->create(['quote_id' => $quote->id, 'product_id' => $product->id, 'job_id' => $shippedJob->id, 'line_state' => LineItemState::Ready->value]);
    LineItem::factory()->create(['quote_id' => $quote->id, 'product_id' => $product->id, 'job_id' => $returnedJob->id, 'line_state' => LineItemState::Ready->value]);

    $snapshot = app(OrderTracker::class)->payload($quote);

    // Two line items, but only the non-returned parcel counts as completed.
    expect($snapshot['items_total'])->toBe(2)
        ->and($snapshot['items_completed'])->toBe(1);
});

// L20: the resource exposes the authoritative needs_proof from LineItem::needsProof().
it('exposes needs_proof on a line resource (L20)', function (): void {
    $company = Company::factory()->create();
    $staff = User::factory()->staffAdmin()->create();
    $product = Product::factory()->create(['class' => 'CORE']);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'state' => 'PROOFING']);

    $plain = LineItem::factory()->create(['quote_id' => $quote->id, 'product_id' => $product->id, 'customization' => null]);
    $custom = LineItem::factory()->create(['quote_id' => $quote->id, 'product_id' => $product->id, 'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/x.png']]);

    Sanctum::actingAs($staff);
    $res = $this->getJson("/api/quotes/{$quote->id}")->assertOk();

    $byId = collect($res->json('data.line_items'))->keyBy('id');
    expect($byId[$plain->id]['needs_proof'])->toBeFalse()
        ->and($byId[$custom->id]['needs_proof'])->toBeTrue();
});
