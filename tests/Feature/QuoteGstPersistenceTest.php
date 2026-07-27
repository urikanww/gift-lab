<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Invoice;
use App\Models\LineItem;
use App\Models\PricingConfig;
use App\Models\Product;
use App\Models\Quote;
use App\Models\User;
use App\Models\Variant;
use App\Services\PricingService;
use App\Services\QuoteService;
use Laravel\Sanctum\Sanctum;

/**
 * GST-2: threads gst_amount/gst_rate persistence (with rate SNAPSHOTTING)
 * through every QuoteService money site - create, amend, reconfirm and
 * invoice issuance - and folds in the fix for the reconfirm-drop fee bug
 * (dropping a customized line used to leave its flat + per-unit
 * customization fee stranded in the subtotal).
 */
beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->staff = User::factory()->staffAdmin()->create();
});

it('stores gst_rate=9 and gst_amount = round((subtotal+delivery)*0.09,2) at create time', function (): void {
    seedPricing();
    Sanctum::actingAs($this->staff);
    $product = Product::factory()->create(['base_cost' => 10, 'print_method' => 'UV', 'publish_state' => 'PUBLISHED']);
    Variant::factory()->create(['product_id' => $product->id]);

    $quote = app(QuoteService::class)->create($this->company->id, [[
        'product_id' => $product->id,
        'variant_id' => null,
        'qty' => 3,
        'customization' => null,
    ]], null);

    $expectedGst = round(((float) $quote->subtotal + (float) $quote->delivery) * 0.09, 2);

    expect((float) $quote->gst_rate)->toBe(9.0)
        ->and((float) $quote->gst_amount)->toBe($expectedGst)
        ->and((float) $quote->total)->toBe(round((float) $quote->subtotal + (float) $quote->delivery + $expectedGst, 2));
});

// SNAPSHOT proof: gst_rate is copied onto the quote at create() and never
// re-read from live config afterward. A superadmin editing tax.gst_pct after
// the quote exists must not reprice it on a later amend.
it('freezes gst_rate at create time - a later tax.gst_pct edit never reprices the quote on amend', function (): void {
    seedPricing();
    Sanctum::actingAs($this->staff);
    $product = Product::factory()->create(['base_cost' => 10, 'print_method' => 'UV', 'publish_state' => 'PUBLISHED']);
    Variant::factory()->create(['product_id' => $product->id]);

    $quote = app(QuoteService::class)->create($this->company->id, [[
        'product_id' => $product->id,
        'variant_id' => null,
        'qty' => 2,
        'customization' => null,
    ]], null);
    $line = $quote->lineItems->first();

    expect((float) $quote->gst_rate)->toBe(9.0);

    // The live config really did change...
    PricingConfig::updateOrCreate(
        ['group' => 'tax', 'key' => 'gst_pct'],
        ['value' => 15, 'label' => 'Singapore GST %', 'is_money' => false, 'currency' => 'SGD'],
    );
    expect(app(PricingService::class)->gstRate())->toBe(0.15);

    app(QuoteService::class)->amend(
        $quote,
        [['id' => $line->id, 'unit_price' => (float) $line->unit_price, 'qty' => 5]],
        null,
        null,
        [],
        null,
        'Buyer asked for more units.',
    );

    $fresh = $quote->fresh();
    // ...but the quote still carries its own FROZEN 9%, not the live 15%.
    expect((float) $fresh->gst_rate)->toBe(9.0);

    $expectedGst = round(((float) $fresh->subtotal + (float) $fresh->delivery) * 0.09, 2);
    expect((float) $fresh->gst_amount)->toBe($expectedGst)
        ->and((float) $fresh->gst_amount)->not->toBe(round(((float) $fresh->subtotal + (float) $fresh->delivery) * 0.15, 2));
});

// Task 4b: reconfirmLine()'s drop branch used to delta the subtotal off bare
// LineItem::lineTotal() (unit x qty), leaving a customized line's flat +
// per-unit decoration fee stranded in the subtotal - the buyer billed a
// setup fee for a line that was never produced. It must now delta off the
// same fee-inclusive lineSubtotalContribution() amend() already uses.
it('drops a customized lines fee-inclusive contribution from the subtotal on reconfirm-drop, and recomputes GST', function (): void {
    seedPricing();
    Sanctum::actingAs($this->staff);
    $product = Product::factory()->create(['base_cost' => 30, 'class' => 'CORE', 'print_method' => 'UV']);
    $variant = Variant::factory()->create(['product_id' => $product->id, 'stock_on_hand' => 0]);
    $quote = Quote::factory()->create([
        'company_id' => $this->company->id,
        'state' => 'PROCURING',
        'subtotal' => 500.00,
        'delivery' => 30.00,
        'gst_rate' => 9,
    ]);
    $line = LineItem::factory()->create([
        'quote_id' => $quote->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'qty' => 10,
        'unit_price' => 40.00,
        'customization' => ['logo_size' => 'M'],
        'line_state' => 'AWAITING_RECONFIRM',
    ]);

    // seedPricing(): fee.customization_flat 8.00, fee.customization_by_size.M 0.40/unit.
    // Fee-inclusive contribution: (10 x 40.00) + 8.00 + (0.40 x 10) = 400 + 8 + 4 = 412.00.
    $bareUnitQty = 400.00;
    $feeInclusiveContribution = 412.00;

    app(QuoteService::class)->reconfirmLine($line, ['action' => 'drop']);

    $fresh = $quote->fresh();

    // NOT the old bug: dropping must pull the WHOLE fee-inclusive figure out,
    // not just the bare unit x qty portion.
    expect((float) $fresh->subtotal)->toBe(round(500.00 - $feeInclusiveContribution, 2))
        ->and((float) $fresh->subtotal)->not->toBe(round(500.00 - $bareUnitQty, 2));

    $expectedGst = round(((float) $fresh->subtotal + (float) $fresh->delivery) * 0.09, 2);
    expect((float) $fresh->gst_amount)->toBe($expectedGst)
        ->and((float) $fresh->total)->toBe(round((float) $fresh->subtotal + (float) $fresh->delivery + $expectedGst, 2));
});

it('issues an invoice whose amount equals the GST-inclusive quote total, with gst_amount/gst_rate persisted', function (): void {
    seedPricing();
    Sanctum::actingAs($this->staff);
    $quote = Quote::factory()->create([
        'company_id' => $this->company->id,
        'state' => 'PROOF_APPROVED',
        'accepted_at' => now(),
        'subtotal' => 500.00,
        'delivery' => 30.00,
        'gst_rate' => 9,
        'gst_amount' => 47.70,
        'total' => 577.70,
    ]);

    $invoice = app(QuoteService::class)->issueInvoice($quote, 'PO-GST', null, null);

    expect((float) $invoice->amount)->toBe((float) $quote->fresh()->total)
        ->and((float) $invoice->amount)->toBe(577.70)
        ->and((float) $invoice->gst_amount)->toBe(47.70)
        ->and((float) $invoice->gst_rate)->toBe(9.0);
});

// Re-anchor guard, reconfirm path: a VOID invoice is a closed, credit-noted
// document (see QuoteService::voidInvoiceAndCredit). A reconfirm decision that
// changes the quote's money must not overwrite the VOID invoice's frozen
// amount - mirrors the same guard in amend().
it('does not overwrite a VOID invoices amount when retotalAfterReconfirm runs', function (): void {
    seedPricing();
    Sanctum::actingAs($this->staff);
    $product = Product::factory()->create(['base_cost' => 30, 'class' => 'CORE', 'print_method' => 'UV']);
    $variant = Variant::factory()->create(['product_id' => $product->id, 'stock_on_hand' => 0]);
    $quote = Quote::factory()->create([
        'company_id' => $this->company->id,
        'state' => 'PROCURING',
        'subtotal' => 500.00,
        'delivery' => 30.00,
        'gst_rate' => 9,
    ]);
    $line = LineItem::factory()->create([
        'quote_id' => $quote->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'qty' => 10,
        'unit_price' => 40.00,
        'customization' => ['logo_size' => 'M'],
        'line_state' => 'AWAITING_RECONFIRM',
    ]);

    $invoice = Invoice::create([
        'quote_id' => $quote->id,
        'po_ref' => 'PO-'.$quote->id,
        'payment_state' => 'VOID',
        'amount' => 888.88,
        'gst_amount' => 0,
        'gst_rate' => 9,
        'currency' => 'SGD',
        'issued_at' => now(),
    ]);

    app(QuoteService::class)->reconfirmLine($line, ['action' => 'drop']);

    expect((float) $invoice->fresh()->amount)->toBe(888.88);
});
