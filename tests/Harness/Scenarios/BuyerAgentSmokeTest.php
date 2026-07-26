<?php

declare(strict_types=1);

use App\Models\Company;
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

    (new BuyerAgent($this->ctx))->accept();

    expect($this->ctx->quote->fresh()->state->value)->toBe('ACCEPTED');
});
