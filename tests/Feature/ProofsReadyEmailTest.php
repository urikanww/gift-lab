<?php

declare(strict_types=1);

use App\Enums\OrderMilestone;
use App\Mail\QuoteReadyMail;
use App\Models\Company;
use App\Models\LineItem;
use App\Models\PricingConfig;
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
    $quote = Quote::factory()->create(['company_id' => $company->id, 'created_by' => $buyer->id, 'state' => 'ACCEPTED', 'accepted_at' => now()]);
    foreach (['Cap', 'Mug'] as $name) {
        $line = LineItem::factory()->create(['quote_id' => $quote->id, 'product_id' => Product::factory()->create(['name' => $name])->id, 'customization' => ['mode' => 'designer', 'artwork_ref' => "artwork/{$name}.png"], 'line_state' => 'PENDING']);
        Proof::factory()->forLine($line)->create(['artwork_version_ref' => "artwork/{$name}.png", 'state' => 'DRAFT']);
    }

    app(QuoteService::class)->sendProofs($quote->fresh());

    Mail::assertQueued(QuoteReadyMail::class, fn (QuoteReadyMail $m): bool => count($m->proofItems) === 2);
});

// The settings screen renders "Revised proof issued" as a live toggle; it must
// actually gate the mail, not just decorate a switch nobody reads.
it('does not send the batched proofs-ready mail when the proof-issued toggle is off', function (): void {
    Mail::fake();
    PricingConfig::updateOrCreate(
        ['group' => 'notifications', 'key' => OrderMilestone::ProofIssued->value],
        ['value' => false],
    );
    $company = Company::factory()->create();
    $buyer = User::factory()->create(['company_id' => $company->id, 'role' => 'buyer']);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'created_by' => $buyer->id, 'state' => 'ACCEPTED', 'accepted_at' => now()]);
    $line = LineItem::factory()->create([
        'quote_id' => $quote->id,
        'product_id' => Product::factory()->create()->id,
        'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/x.png'],
        'line_state' => 'PENDING',
    ]);
    Proof::factory()->forLine($line)->create(['artwork_version_ref' => 'artwork/x.png', 'state' => 'DRAFT']);

    app(QuoteService::class)->sendProofs($quote->fresh());

    Mail::assertNotQueued(QuoteReadyMail::class);
});

it('sends the batched proofs-ready mail when the proof-issued toggle is on (its default)', function (): void {
    Mail::fake();
    $company = Company::factory()->create();
    $buyer = User::factory()->create(['company_id' => $company->id, 'role' => 'buyer']);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'created_by' => $buyer->id, 'state' => 'ACCEPTED', 'accepted_at' => now()]);
    $line = LineItem::factory()->create([
        'quote_id' => $quote->id,
        'product_id' => Product::factory()->create()->id,
        'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/x.png'],
        'line_state' => 'PENDING',
    ]);
    Proof::factory()->forLine($line)->create(['artwork_version_ref' => 'artwork/x.png', 'state' => 'DRAFT']);

    app(QuoteService::class)->sendProofs($quote->fresh());

    Mail::assertQueued(QuoteReadyMail::class);
});
