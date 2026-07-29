<?php

declare(strict_types=1);

use App\Enums\Carrier;
use App\Enums\JobState;
use App\Enums\StockMovementReason;
use App\Models\Company;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\ProductionJob;
use App\Models\Quote;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Variant;
use App\Services\Courier\NinjaVanStatusMapper;
use App\Services\QueueService;

/**
 * M15: cancel & credit ONE returned parcel of a multi-parcel order without
 * disturbing its delivered siblings. Fixture builds two equal-value parcels
 * (fraction 0.5 each) on one paid order, directly (no courier round-trip).
 *
 * @return array{quote: Quote, jobA: ProductionJob, jobB: ProductionJob, invoice: Invoice}
 */
function twoParcelOrder(string $paymentState = 'PAID', ?float $amountPaid = null): array
{
    $company = Company::factory()->create();
    $quote = Quote::factory()->create([
        'company_id' => $company->id,
        'state' => 'CONFIRMED',
        'accepted_at' => now(),
        'total' => 1000.00,
        'gst_amount' => 82.57,
        'currency' => 'SGD',
    ]);

    $product = Product::factory()->create(['class' => 'CORE']);

    $recordedPaid = $amountPaid ?? ($paymentState === 'PAID' ? 1000.00 : null);
    $invoice = Invoice::create([
        'quote_id' => $quote->id,
        'po_ref' => 'PO-'.$quote->id,
        'invoice_ref' => null,
        'terms' => 'NET30',
        'payment_state' => $paymentState,
        'amount' => 1000.00,
        'amount_paid' => $recordedPaid,
        'gst_amount' => 82.57,
        'gst_rate' => 9,
        'currency' => 'SGD',
        'issued_by' => null,
        'issued_at' => now(),
    ]);

    $jobA = ProductionJob::factory()->create([
        'quote_id' => $quote->id,
        'state' => JobState::Shipped->value,
        'consignment_ref' => 'NVSGA0001',
        'carrier' => Carrier::NinjaVan->value,
        'last_courier_status' => NinjaVanStatusMapper::map('Returned to Sender')->label,
        'last_courier_status_at' => now(),
    ]);
    $jobB = ProductionJob::factory()->create([
        'quote_id' => $quote->id,
        'state' => JobState::Shipped->value,
        'consignment_ref' => 'NVSGB0001',
        'carrier' => Carrier::NinjaVan->value,
    ]);

    // One equal-value line per parcel: 500 each → fraction 0.5.
    $variantA = Variant::factory()->create(['product_id' => $product->id]);
    $variantB = Variant::factory()->create(['product_id' => $product->id]);
    $lineA = LineItem::factory()->ready()->create([
        'quote_id' => $quote->id, 'product_id' => $product->id, 'variant_id' => $variantA->id,
        'job_id' => $jobA->id, 'unit_price' => 500.00, 'qty' => 1, 'customization' => null,
    ]);
    LineItem::factory()->ready()->create([
        'quote_id' => $quote->id, 'product_id' => $product->id, 'variant_id' => $variantB->id,
        'job_id' => $jobB->id, 'unit_price' => 500.00, 'qty' => 1, 'customization' => null,
    ]);

    // Parcel A's line consumed 10 units of stock at procurement (a SALE
    // movement) so there is something to restock on return.
    StockMovement::create([
        'variant_id' => $variantA->id,
        'delta' => -10,
        'reason' => StockMovementReason::Sale->value,
        'ref_type' => $lineA->getMorphClass(),
        'ref_id' => $lineA->id,
    ]);

    return ['quote' => $quote, 'jobA' => $jobA->fresh(), 'jobB' => $jobB->fresh(), 'invoice' => $invoice];
}

it('returns ONLY the one parcel and leaves the order + sibling live (M15, fully paid)', function (): void {
    ['quote' => $quote, 'jobA' => $jobA, 'jobB' => $jobB, 'invoice' => $invoice] = twoParcelOrder('PAID');

    app(QueueService::class)->resolveReturn($jobA, 'cancel_credit', 'Box A returned');

    // The returned parcel is terminal RETURNED; the sibling is untouched.
    expect($jobA->fresh()->state)->toBe(JobState::Returned)
        ->and($jobB->fresh()->state)->toBe(JobState::Shipped);

    // The order itself is NOT cancelled - it still has a delivered parcel.
    expect($quote->fresh()->state->value)->toBe('CONFIRMED');

    // Credit only this parcel's collected share (0.5 × 1000 = 500), and the
    // invoice now bills only the retained goods (1000 → 500).
    $credits = CreditNote::where('quote_id', $quote->id)->get();
    expect($credits)->toHaveCount(1)
        ->and((float) $credits->first()->amount)->toBe(500.00);

    $invoice->refresh();
    expect((float) $invoice->amount)->toBe(500.00)
        ->and((float) $invoice->amount_paid)->toBe(500.00)
        ->and($invoice->payment_state->value)->toBe('PAID');
});

it('restocks ONLY the returned parcels lines (M15)', function (): void {
    ['jobA' => $jobA] = twoParcelOrder('PAID');
    $lineA = $jobA->lineItems()->first();

    app(QueueService::class)->resolveReturn($jobA, 'cancel_credit', 'Box A returned');

    // A compensating RETURN movement of +10 for parcel A's line only.
    $returned = StockMovement::where('ref_type', $lineA->getMorphClass())
        ->where('ref_id', $lineA->id)
        ->where('reason', StockMovementReason::Return->value)
        ->sum('delta');
    expect((int) $returned)->toBe(10);
});

it('credits only the parcels PROPORTIONAL share of a deposit (M15, part-paid)', function (): void {
    // Collected 400 of 1000; parcel is half the order → refund 0.5 × 400 = 200.
    ['quote' => $quote, 'jobA' => $jobA, 'invoice' => $invoice] = twoParcelOrder('PARTIAL', 400.00);

    app(QueueService::class)->resolveReturn($jobA, 'cancel_credit', 'Box A returned');

    $credits = CreditNote::where('quote_id', $quote->id)->get();
    expect($credits)->toHaveCount(1)
        ->and((float) $credits->first()->amount)->toBe(200.00);

    $invoice->refresh();
    expect((float) $invoice->amount)->toBe(500.00)
        ->and((float) $invoice->amount_paid)->toBe(200.00);
});

it('falls back to a whole-order cancel when the returned parcel is the last live one (M15)', function (): void {
    ['quote' => $quote, 'jobA' => $jobA, 'jobB' => $jobB] = twoParcelOrder('PAID');

    // Sibling B is already gone (returned earlier) → A is the last live parcel.
    $jobB->update(['state' => JobState::Returned->value]);

    app(QueueService::class)->resolveReturn($jobA, 'cancel_credit', 'Last box returned');

    expect($jobA->fresh()->state)->toBe(JobState::Returned)
        ->and($quote->fresh()->state->value)->toBe('CANCELLED');

    // Whole-order cancel voids the invoice and credits what was collected.
    expect(Invoice::where('quote_id', $quote->id)->first()->payment_state->value)->toBe('VOID');
});
