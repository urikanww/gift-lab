<?php

declare(strict_types=1);

use App\Mail\OrderMilestoneMail;
use App\Models\Company;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\Harness\Agents\BuyerAgent;
use Tests\Harness\Support\HarnessContext;

beforeEach(function (): void {
    Mail::fake();
    $this->company = Company::factory()->create();
    $this->buyer = User::factory()->create(['company_id' => $this->company->id, 'role' => 'buyer']);
    $this->staff = User::factory()->staffAdmin()->create();
    $this->ctx = new HarnessContext($this, $this->staff, $this->buyer);
});

it('chases an order the buyer went silent on', function (): void {
    // A SENT quote waiting 3 days (past the first chase rung), created by the buyer.
    $quote = Quote::factory()->create([
        'company_id' => $this->company->id,
        'state' => 'SENT',
        'created_by' => $this->buyer->id,
        'price_snapshot_at' => now()->subDays(3),
    ]);
    $this->ctx->quote = $quote;

    // Buyer does nothing.
    (new BuyerAgent($this->ctx))->goSilent();

    // The nightly chase runs and nudges the un-actioned order exactly once.
    $this->artisan('quotes:chase')->assertSuccessful();

    expect($quote->fresh()->reminders_sent)->toBe(1);
    Mail::assertQueued(OrderMilestoneMail::class);
});
