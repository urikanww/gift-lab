<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\User;
use App\Services\QuoteService;
use Laravel\Sanctum\Sanctum;

/**
 * B2B invoices have no Stripe path: a staff member must be able to record
 * that a purchase order was actually paid (or partially paid, or voided).
 * Before this, PaymentState::Paid was only ever written by the B2C pay-now
 * webhook path, so every B2B invoice sat UNPAID forever.
 */
function confirmedQuoteWithInvoice(int $companyId, string $paymentState = 'UNPAID'): Quote
{
    $quote = Quote::factory()->create([
        'company_id' => $companyId,
        'state' => 'CONFIRMED',
        'accepted_at' => now(),
        'total' => 100,
    ]);

    Invoice::create([
        'quote_id' => $quote->id,
        'po_ref' => 'PO-'.$quote->id,
        'invoice_ref' => null,
        'terms' => null,
        'payment_state' => $paymentState,
        'amount' => 100,
        'currency' => 'SGD',
        'issued_by' => null,
        'issued_at' => now(),
    ]);

    return $quote;
}

beforeEach(function (): void {
    $this->company = Company::factory()->create();
});

it('lets staff mark a B2B invoice PAID with an audited actor', function (): void {
    $staff = User::factory()->staffAdmin()->create();
    $quote = confirmedQuoteWithInvoice($this->company->id);

    Sanctum::actingAs($staff);
    $response = $this->postJson("/api/quotes/{$quote->id}/payment", ['payment_state' => 'PAID'])
        ->assertOk();

    expect($response->json('invoice.payment_state'))->toBe('PAID');

    $this->assertDatabaseHas('invoices', [
        'quote_id' => $quote->id,
        'payment_state' => 'PAID',
    ]);

    $invoice = Invoice::where('quote_id', $quote->id)->firstOrFail();
    $audit = AuditLog::where('auditable_type', Invoice::class)
        ->where('auditable_id', $invoice->id)
        ->where('event', 'payment.reconciled')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->user_id)->toBe($staff->id)
        ->and($audit->created_at)->not->toBeNull();
});

it('supports marking an invoice PARTIAL', function (): void {
    $staff = User::factory()->staffAdmin()->create();
    $quote = confirmedQuoteWithInvoice($this->company->id);

    Sanctum::actingAs($staff);
    $this->postJson("/api/quotes/{$quote->id}/payment", ['payment_state' => 'PARTIAL', 'note' => 'Deposit received'])
        ->assertOk();

    $this->assertDatabaseHas('invoices', [
        'quote_id' => $quote->id,
        'payment_state' => 'PARTIAL',
    ]);
});

it('supports voiding an invoice', function (): void {
    $staff = User::factory()->staffAdmin()->create();
    $quote = confirmedQuoteWithInvoice($this->company->id);

    Sanctum::actingAs($staff);
    $this->postJson("/api/quotes/{$quote->id}/payment", ['payment_state' => 'VOID'])
        ->assertOk();

    $this->assertDatabaseHas('invoices', [
        'quote_id' => $quote->id,
        'payment_state' => 'VOID',
    ]);
});

it('refuses to reconcile a VOID invoice to another state', function (): void {
    $staff = User::factory()->staffAdmin()->create();
    $quote = confirmedQuoteWithInvoice($this->company->id, 'VOID');

    Sanctum::actingAs($staff);
    $this->postJson("/api/quotes/{$quote->id}/payment", ['payment_state' => 'PAID'])
        ->assertStatus(422);

    $this->assertDatabaseHas('invoices', [
        'quote_id' => $quote->id,
        'payment_state' => 'VOID',
    ]);
});

it('is idempotent when reconciling to the state the invoice is already in', function (): void {
    $staff = User::factory()->staffAdmin()->create();
    $quote = confirmedQuoteWithInvoice($this->company->id, 'PAID');

    Sanctum::actingAs($staff);
    $this->postJson("/api/quotes/{$quote->id}/payment", ['payment_state' => 'PAID'])
        ->assertOk();

    $this->assertDatabaseHas('invoices', [
        'quote_id' => $quote->id,
        'payment_state' => 'PAID',
    ]);
});

it('rejects reconciliation for a quote with no invoice', function (): void {
    $staff = User::factory()->staffAdmin()->create();
    $quote = Quote::factory()->create(['company_id' => $this->company->id, 'state' => 'PROOF_APPROVED']);

    Sanctum::actingAs($staff);
    $this->postJson("/api/quotes/{$quote->id}/payment", ['payment_state' => 'PAID'])
        ->assertStatus(422);
});

it('403s a staff_admin without quotes.edit reconciling payment', function (): void {
    $staff = User::factory()->staffAdmin()->create(['permissions' => ['quotes.view']]);
    $quote = confirmedQuoteWithInvoice($this->company->id);

    Sanctum::actingAs($staff);
    $this->postJson("/api/quotes/{$quote->id}/payment", ['payment_state' => 'PAID'])
        ->assertStatus(403);

    $this->assertDatabaseHas('invoices', [
        'quote_id' => $quote->id,
        'payment_state' => 'UNPAID',
    ]);
});

it('forbids a buyer from reconciling payment even though the middleware lets them through', function (): void {
    $buyer = User::factory()->create(['company_id' => $this->company->id, 'role' => 'buyer']);
    $quote = confirmedQuoteWithInvoice($this->company->id);

    Sanctum::actingAs($buyer);
    $this->postJson("/api/quotes/{$quote->id}/payment", ['payment_state' => 'PAID'])
        ->assertStatus(403);
});

it('rejects an invalid target payment state', function (): void {
    $staff = User::factory()->staffAdmin()->create();
    $quote = confirmedQuoteWithInvoice($this->company->id);

    Sanctum::actingAs($staff);
    $this->postJson("/api/quotes/{$quote->id}/payment", ['payment_state' => 'BOGUS'])
        ->assertStatus(422);
});

// Bug 2: issueInvoice must lock the quote and short-circuit if an invoice
// already exists, so a concurrent double-submit never creates two invoices
// for the same quote.
it('short-circuits issueInvoice to the existing invoice instead of creating a duplicate', function (): void {
    $staff = User::factory()->staffAdmin()->create();
    $quote = Quote::factory()->create([
        'company_id' => $this->company->id,
        'state' => 'PROOF_APPROVED',
        'accepted_at' => now(),
        'total' => 100,
    ]);

    $service = app(QuoteService::class);

    Sanctum::actingAs($staff);
    $first = $service->issueInvoice($quote, 'PO-FIRST', null, null);
    $second = $service->issueInvoice($quote->fresh(), 'PO-SECOND', null, null);

    expect($second->id)->toBe($first->id);
    expect(Invoice::where('quote_id', $quote->id)->count())->toBe(1);
});
