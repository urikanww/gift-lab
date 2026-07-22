<?php

declare(strict_types=1);

use App\Events\ProofChangesRequested;
use App\Mail\ProofChangesRequestedMail;
use App\Models\Company;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Proof;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\postJson;

/** A PROOFING quote (buyer accepted the price) with one open proof + a buyer. */
function changeRequestFixture(): array
{
    $company = Company::factory()->create();
    $buyer = User::factory()->create(['company_id' => $company->id, 'role' => 'buyer']);
    $product = Product::factory()->create(['base_cost' => 1]);

    $quote = Quote::factory()->create([
        'company_id' => $company->id,
        'state' => 'PROOFING',
        'accepted_at' => now(),
    ]);
    LineItem::factory()->create(['quote_id' => $quote->id, 'product_id' => $product->id]);
    $proof = Proof::factory()->create(['quote_id' => $quote->id, 'state' => 'SENT', 'version' => 1]);

    return [$buyer, $proof];
}

it('emails every operator and pushes a staff event when a buyer requests changes', function (): void {
    Mail::fake();
    Event::fake([ProofChangesRequested::class]);

    // Two internal operators with addresses; the buyer must NOT be a recipient.
    User::factory()->staffAdmin()->create(['email' => 'ops@nexgen.test']);
    User::factory()->create(['role' => 'superadmin', 'email' => 'boss@nexgen.test']);

    [$buyer, $proof] = changeRequestFixture();
    Sanctum::actingAs($buyer);

    postJson("/api/proofs/{$proof->id}/decide", [
        'decision' => 'request_changes',
        'notes' => 'Move the logo up.',
    ])->assertOk();

    // Email to each operator (2), and the live push to the console.
    Mail::assertQueued(ProofChangesRequestedMail::class, 2);
    Event::assertDispatched(ProofChangesRequested::class);
});

it('rejects a change request with no note and notifies nobody', function (): void {
    Mail::fake();
    User::factory()->staffAdmin()->create(['email' => 'ops@nexgen.test']);

    [$buyer, $proof] = changeRequestFixture();
    Sanctum::actingAs($buyer);

    // The API requires a reason with request_changes (required_if).
    postJson("/api/proofs/{$proof->id}/decide", ['decision' => 'request_changes'])->assertStatus(422);

    Mail::assertNothingQueued();
});
