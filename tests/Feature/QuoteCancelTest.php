<?php

declare(strict_types=1);

use App\Exceptions\InvalidStateTransitionException;
use App\Models\AuditLog;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\User;
use App\Services\QuoteService;
use Laravel\Sanctum\Sanctum;

it('lets staff cancel a quote', function (): void {
    $quote = Quote::factory()->create(['state' => 'SENT']);
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->postJson("/api/quotes/{$quote->id}/cancel", ['reason' => 'duplicate'])
        ->assertOk()
        ->assertJsonPath('data.state', 'CANCELLED');
});

it('lets a superadmin cancel a quote', function (): void {
    $quote = Quote::factory()->create(['state' => 'SENT']);
    Sanctum::actingAs(User::factory()->superadmin()->create());

    $this->postJson("/api/quotes/{$quote->id}/cancel")->assertOk();
});

it('forbids a buyer from cancelling their own company quote', function (): void {
    $buyer = User::factory()->create(); // buyer
    $quote = Quote::factory()->create(['company_id' => $buyer->company_id, 'state' => 'SENT']);
    Sanctum::actingAs($buyer);

    $this->postJson("/api/quotes/{$quote->id}/cancel")->assertStatus(403);
    expect($quote->refresh()->state->value)->toBe('SENT');
});

it('refuses to cancel a READY quote', function (): void {
    $quote = Quote::factory()->create(['state' => 'READY']);
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    // transitionTo throws InvalidStateTransitionException, mapped to 422 by the
    // handler in bootstrap/app.php; the DB::transaction rolls back so state stays.
    $this->postJson("/api/quotes/{$quote->id}/cancel")->assertStatus(422);
    expect($quote->refresh()->state->value)->toBe('READY');
});

it('validates the reason length', function (): void {
    $quote = Quote::factory()->create(['state' => 'SENT']);
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->postJson("/api/quotes/{$quote->id}/cancel", ['reason' => str_repeat('x', 501)])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);
});

/**
 * The money-loop gap: cancel() used to transition a quote to CANCELLED and
 * return stock without ever touching its invoice, so a PAID B2B invoice
 * could sit CANCELLED-but-still-PAID/collectible. B2B has no gateway to
 * refund through, so the correct closure is a credit-note record + invoice
 * VOID - see QuoteService::voidInvoiceAndCredit().
 */
function invoicedQuote(string $paymentState): Quote
{
    $quote = Quote::factory()->create([
        'state' => 'CONFIRMED',
        'accepted_at' => now(),
        'total' => 250.75,
        'gst_amount' => 20.70,
    ]);

    Invoice::create([
        'quote_id' => $quote->id,
        'po_ref' => 'PO-'.$quote->id,
        'invoice_ref' => null,
        'terms' => null,
        'payment_state' => $paymentState,
        'amount' => 250.75,
        'gst_amount' => 20.70,
        'gst_rate' => 9,
        'currency' => 'SGD',
        'issued_by' => null,
        'issued_at' => now(),
    ]);

    return $quote;
}

it('voids a PAID invoice and mints exactly one credit note for the GST-inclusive amount on cancel', function (): void {
    $quote = invoicedQuote('PAID');
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->postJson("/api/quotes/{$quote->id}/cancel", ['reason' => 'Buyer withdrew'])
        ->assertOk()
        ->assertJsonPath('data.state', 'CANCELLED');

    $invoice = Invoice::where('quote_id', $quote->id)->firstOrFail();
    expect($invoice->payment_state->value)->toBe('VOID');

    $creditNotes = CreditNote::where('quote_id', $quote->id)->get();
    expect($creditNotes)->toHaveCount(1);
    $creditNote = $creditNotes->first();
    expect((float) $creditNote->amount)->toBe(250.75)
        ->and($creditNote->invoice_id)->toBe($invoice->id);
});

it('voids a PARTIAL invoice and mints exactly one credit note for the invoice amount on cancel', function (): void {
    $quote = invoicedQuote('PARTIAL');
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    app(QuoteService::class)->cancel($quote, 'Buyer withdrew');

    $invoice = Invoice::where('quote_id', $quote->id)->firstOrFail();
    expect($invoice->payment_state->value)->toBe('VOID');
    expect(CreditNote::where('quote_id', $quote->id)->count())->toBe(1);
    expect((float) CreditNote::where('quote_id', $quote->id)->first()->amount)->toBe(250.75);
});

it('voids an UNPAID invoice on cancel without minting any credit note', function (): void {
    $quote = invoicedQuote('UNPAID');
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    app(QuoteService::class)->cancel($quote, 'Buyer withdrew');

    $invoice = Invoice::where('quote_id', $quote->id)->firstOrFail();
    expect($invoice->payment_state->value)->toBe('VOID');
    expect(CreditNote::where('quote_id', $quote->id)->count())->toBe(0);
});

it('cancels cleanly with no credit note or invoice touch when the quote was never invoiced', function (): void {
    $quote = Quote::factory()->create(['state' => 'SENT']);
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    app(QuoteService::class)->cancel($quote, 'Buyer withdrew');

    expect($quote->fresh()->state->value)->toBe('CANCELLED');
    expect(Invoice::where('quote_id', $quote->id)->count())->toBe(0);
    expect(CreditNote::where('quote_id', $quote->id)->count())->toBe(0);
});

it('audits the invoice void and the credit note issuance on cancel', function (): void {
    $quote = invoicedQuote('PAID');
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    app(QuoteService::class)->cancel($quote, 'Buyer withdrew');

    $invoice = Invoice::where('quote_id', $quote->id)->firstOrFail();
    $creditNote = CreditNote::where('quote_id', $quote->id)->firstOrFail();

    expect(AuditLog::where('auditable_type', Invoice::class)
        ->where('auditable_id', $invoice->id)
        ->where('event', 'invoice.voided')
        ->exists())->toBeTrue();

    expect(AuditLog::where('auditable_type', CreditNote::class)
        ->where('auditable_id', $creditNote->id)
        ->where('event', 'credit_note.issued')
        ->exists())->toBeTrue();
});

it('refuses a second cancel and does not mint a duplicate credit note', function (): void {
    $quote = invoicedQuote('PAID');
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    app(QuoteService::class)->cancel($quote, 'Buyer withdrew');
    expect(CreditNote::where('quote_id', $quote->id)->count())->toBe(1);

    expect(fn () => app(QuoteService::class)->cancel($quote->fresh(), 'Second cancel attempt'))
        ->toThrow(InvalidStateTransitionException::class);

    expect(CreditNote::where('quote_id', $quote->id)->count())->toBe(1);
    expect(Invoice::where('quote_id', $quote->id)->first()->payment_state->value)->toBe('VOID');
});
