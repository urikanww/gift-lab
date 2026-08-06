<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Quote;
use App\Models\User;
use Tests\Harness\Agents\BuyerAgent;
use Tests\Harness\Support\HarnessContext;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->buyer = User::factory()->create(['company_id' => $this->company->id, 'role' => 'buyer']);
    $this->staff = User::factory()->staffAdmin()->create();
    $this->ctx = new HarnessContext($this, $this->staff, $this->buyer);
});

it('BuyerAgent accepts a SENT quote', function (): void {
    $this->ctx->quote = Quote::factory()->create([
        'company_id' => $this->company->id,
        'state' => 'SENT',
    ]);
    // A proof-needing line keeps the order at ACCEPTED after the buyer agrees the
    // price (staff still have a proof to prepare). Without any line, accept()
    // auto-advances a plain-stock order straight to PROOF_APPROVED.
    $product = Product::factory()->create(['class' => 'SCRAPED_UV', 'print_method' => 'UV']);
    LineItem::factory()->create([
        'quote_id' => $this->ctx->quote->id,
        'product_id' => $product->id,
        'qty' => 5,
        'customization' => ['mode' => 'designer'],
    ]);

    (new BuyerAgent($this->ctx))->accept();

    expect($this->ctx->quote->fresh()->state->value)->toBe('ACCEPTED');
});
