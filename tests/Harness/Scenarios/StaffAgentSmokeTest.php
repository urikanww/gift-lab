<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use Tests\Harness\Agents\StaffAgent;
use Tests\Harness\Support\HarnessContext;

beforeEach(function (): void {
    seedPricing();
    $this->company = Company::factory()->create();
    $this->buyer = User::factory()->create(['company_id' => $this->company->id, 'role' => 'buyer']);
    $this->staff = User::factory()->staffAdmin()->create();
    $this->product = Product::factory()->create(['base_cost' => 10, 'print_method' => 'UV', 'publish_state' => 'PUBLISHED']);
    Variant::factory()->create(['product_id' => $this->product->id]);
    $this->ctx = new HarnessContext($this, $this->staff, $this->buyer);
});

it('StaffAgent creates a draft and sends it to the buyer', function (): void {
    $staff = new StaffAgent($this->ctx);

    $staff->createDraft($this->company->id, [
        ['product_id' => $this->product->id, 'variant_id' => null, 'qty' => 3],
    ]);

    expect($this->ctx->quote->state->value)->toBe('DRAFT');

    $staff->send();

    expect($this->ctx->quote->fresh()->state->value)->toBe('SENT');
});
