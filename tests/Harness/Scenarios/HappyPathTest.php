<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Harness\Agents\BuyerAgent;
use Tests\Harness\Agents\ProductionAgent;
use Tests\Harness\Agents\StaffAgent;
use Tests\Harness\Agents\ValidatorAgent;
use Tests\Harness\Support\HarnessContext;

beforeEach(function (): void {
    Mail::fake();
    // The line-item artwork_ref must resolve on the artwork disk (StoreQuoteRequest
    // guards against a well-formed but foreign/guessed key reaching the floor).
    Storage::fake((string) config('filesystems.artwork_disk'));
    Storage::disk((string) config('filesystems.artwork_disk'))->put('artwork/logo-v1.png', 'fake-artwork-bytes');
    seedPricing();
    $this->company = Company::factory()->create();
    $this->buyer = User::factory()->create(['company_id' => $this->company->id, 'role' => 'buyer']);
    $this->staff = User::factory()->staffAdmin()->create();
    $this->product = Product::factory()->create(['base_cost' => 10, 'print_method' => 'UV', 'publish_state' => 'PUBLISHED', 'class' => 'CORE']);
    Variant::factory()->create(['product_id' => $this->product->id, 'stock_on_hand' => 100]);
    $this->ctx = new HarnessContext($this, $this->staff, $this->buyer);
});

it('runs a customized order from draft to closed with no violations', function (): void {
    $staff = new StaffAgent($this->ctx);
    $buyer = new BuyerAgent($this->ctx);
    $production = new ProductionAgent($this->ctx);
    $validator = new ValidatorAgent($this->ctx);

    // 1. Staff drafts a customized (proofable) line and sends it.
    $staff->createDraft($this->company->id, [
        [
            'product_id' => $this->product->id,
            'variant_id' => null,
            'qty' => 5,
            'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/logo-v1.png'],
        ],
    ]);
    $staff->send();
    expect($this->ctx->quote->fresh()->state->value)->toBe('SENT');

    // 2. Buyer accepts the price first (price-first route).
    $buyer->accept();
    expect($this->ctx->quote->fresh()->state->value)->toBe('ACCEPTED');

    // 3. Staff issues the proof for the line; buyer approves it.
    $line = $this->ctx->quote->fresh('lineItems')->lineItems->first();
    $proof = $staff->stageProof($line, 'artwork/logo-v1.png');
    $staff->sendProofs();
    $buyer->approveProof($proof['id']);
    // Price agreed before sign-off => PROOF_APPROVED, not ARTWORK_APPROVED.
    expect($this->ctx->quote->fresh()->state->value)->toBe('PROOF_APPROVED');

    // 4. Staff invoices (drives INVOICED -> CONFIRMED) then procures.
    $staff->issueInvoice('PO-HAPPY-1');
    expect($this->ctx->quote->fresh()->state->value)->toBe('CONFIRMED');
    $staff->procure();
    expect($this->ctx->quote->fresh()->state->value)->toBe('PROCURING');
    // Procurement resolves the lines but does not release the order to the
    // floor by itself; a person must confirm the goods are actually in hand.
    $staff->confirmStock();
    expect($this->ctx->quote->fresh()->state->value)->toBe('READY');

    // 5. Each built job carries a print file (per-line-file invariant, scenario form).
    $jobs = $this->ctx->quote->fresh('jobs')->jobs;
    expect($jobs)->not->toBeEmpty();
    foreach ($jobs as $job) {
        expect($job->artwork_refs)->not->toBeEmpty();
    }

    // 6. Floor runs each job to CLOSED; the last close closes the quote.
    foreach ($jobs as $job) {
        $production->startJob($job);
        $production->ship($job);
        $production->closeJob($job);
    }
    expect($this->ctx->quote->fresh()->state->value)->toBe('CLOSED');

    // 7. Invariant: billed == produced across every line.
    $validator->check('INVOICE_MATCHES_PRODUCED');
    expect($validator->violations())->toBeEmpty(
        implode("\n", array_map('strval', $validator->violations())),
    );
});
