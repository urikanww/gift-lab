<?php

declare(strict_types=1);

use App\Mail\QuoteReadyMail;
use App\Models\Company;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Proof;
use App\Models\Quote;
use App\Models\User;
use App\Services\QuoteService;
use Illuminate\Support\Facades\Mail;

it('lists every item in the round on one email', function (): void {
    Mail::fake();
    $company = Company::factory()->create();
    $buyer = User::factory()->create(['company_id' => $company->id, 'role' => 'buyer']);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'created_by' => $buyer->id, 'state' => 'ACCEPTED']);
    foreach (['Cap', 'Mug'] as $name) {
        $line = LineItem::factory()->create(['quote_id' => $quote->id, 'product_id' => Product::factory()->create(['name' => $name])->id, 'customization' => ['mode' => 'designer', 'artwork_ref' => "artwork/{$name}.png"], 'line_state' => 'PENDING']);
        Proof::factory()->forLine($line)->create(['artwork_version_ref' => "artwork/{$name}.png", 'state' => 'DRAFT']);
    }

    app(QuoteService::class)->sendProofs($quote->fresh());

    Mail::assertQueued(QuoteReadyMail::class, fn (QuoteReadyMail $m): bool => count($m->proofItems) === 2);
});
