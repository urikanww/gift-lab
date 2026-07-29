<?php

declare(strict_types=1);

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\User;
use App\Services\QuoteService;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\postJson;

/** A CONFIRMED, invoiced quote with a fresh UNPAID invoice to reconcile. */
function reconcilableQuote(): Quote
{
    $quote = Quote::factory()->create([
        'state' => 'CONFIRMED',
        'accepted_at' => now(),
        'total' => 1000.00,
        'gst_amount' => 82.57,
    ]);

    Invoice::create([
        'quote_id' => $quote->id,
        'po_ref' => 'PO-'.$quote->id,
        'invoice_ref' => null,
        'terms' => 'NET30',
        'payment_state' => 'UNPAID',
        'amount' => 1000.00,
        'gst_amount' => 82.57,
        'gst_rate' => 9,
        'currency' => 'SGD',
        'issued_by' => null,
        'issued_at' => now(),
    ]);

    return $quote;
}

it('records the collected amount when reconciling to PARTIAL', function (): void {
    $quote = reconcilableQuote();
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    postJson("/api/quotes/{$quote->id}/payment", [
        'payment_state' => 'PARTIAL',
        'amount_paid' => 400.00,
        'note' => 'First deposit',
    ])->assertOk();

    $invoice = Invoice::where('quote_id', $quote->id)->firstOrFail();
    expect($invoice->payment_state->value)->toBe('PARTIAL')
        ->and((float) $invoice->amount_paid)->toBe(400.00)
        ->and($invoice->balanceOwed())->toBe(600.00);
});

it('rejects a PARTIAL reconcile with no amount', function (): void {
    $quote = reconcilableQuote();
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    postJson("/api/quotes/{$quote->id}/payment", [
        'payment_state' => 'PARTIAL',
    ])->assertStatus(422);
});

it('rejects a PARTIAL amount that meets or exceeds the invoice total (that is PAID)', function (): void {
    $quote = reconcilableQuote();
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    postJson("/api/quotes/{$quote->id}/payment", [
        'payment_state' => 'PARTIAL',
        'amount_paid' => 1000.00,
    ])->assertStatus(422);
});

it('stamps the full amount as collected when reconciling to PAID', function (): void {
    $quote = reconcilableQuote();
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    postJson("/api/quotes/{$quote->id}/payment", [
        'payment_state' => 'PAID',
    ])->assertOk();

    $invoice = Invoice::where('quote_id', $quote->id)->firstOrFail();
    expect((float) $invoice->amount_paid)->toBe(1000.00)
        ->and($invoice->balanceOwed())->toBe(0.0);
});

it('credits ONLY the collected amount when a PARTIAL invoice is cancelled (H3)', function (): void {
    $quote = reconcilableQuote();
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    // Staff collected 400 of 1000, then the order is cancelled.
    app(QuoteService::class)->reconcilePayment($quote, App\Enums\PaymentState::Partial, 'deposit', 400.00);
    app(QuoteService::class)->cancel($quote->fresh(), 'Buyer withdrew');

    $creditNotes = CreditNote::where('quote_id', $quote->id)->get();
    expect($creditNotes)->toHaveCount(1)
        ->and((float) $creditNotes->first()->amount)->toBe(400.00);

    expect(Invoice::where('quote_id', $quote->id)->first()->payment_state->value)->toBe('VOID');
});
