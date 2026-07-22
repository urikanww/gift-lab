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
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

beforeEach(function (): void {
    $this->superadmin = User::factory()->create(['role' => 'superadmin', 'name' => 'Super Admin']);
    $this->company = Company::factory()->create();
    $this->buyer = User::factory()->create(['company_id' => $this->company->id, 'role' => 'buyer']);
    $this->product = Product::factory()->create(['base_cost' => 1]);
});

/** A PROOFING quote (buyer already accepted the price) with one open proof. */
function proofingQuote(): array
{
    $quote = Quote::factory()->create([
        'company_id' => test()->company->id,
        'state' => 'PROOFING',
        'accepted_at' => now(),
    ]);
    LineItem::factory()->create(['quote_id' => $quote->id, 'product_id' => test()->product->id]);
    $proof = Proof::factory()->create(['quote_id' => $quote->id, 'state' => 'SENT', 'version' => 1]);

    return [$quote, $proof];
}

// ---- creation seeds the Draft history entry -----------------------------

it('seeds a Draft entry in the status history at creation', function (): void {
    Sanctum::actingAs($this->buyer);

    $quote = app(QuoteService::class)->create(
        $this->company->id,
        [['product_id' => $this->product->id, 'qty' => 2]],
        null,
    );

    Sanctum::actingAs($this->superadmin);
    $res = getJson("/api/quotes/{$quote->reference}/history")->assertOk();

    // Oldest-first: the very first recorded entry is the move INTO Draft.
    $first = $res->json('data.0');
    expect($first['to'])->toBe('DRAFT')
        ->and($first['from'])->toBeNull()
        ->and($first['changed_at'])->not->toBeNull();
});

// ---- resend proof email --------------------------------------------------

it('lets a superadmin resend the buyer proof email', function (): void {
    Mail::fake();
    [, $proof] = proofingQuote();
    Sanctum::actingAs($this->superadmin);

    postJson("/api/proofs/{$proof->id}/resend")->assertOk();

    Mail::assertQueued(QuoteReadyMail::class);
});

it('refuses to resend a proof that is no longer open', function (): void {
    Mail::fake();
    [$quote] = proofingQuote();
    $approved = Proof::factory()->create(['quote_id' => $quote->id, 'state' => 'APPROVED', 'version' => 2]);
    Sanctum::actingAs($this->superadmin);

    postJson("/api/proofs/{$approved->id}/resend")->assertStatus(422);
    Mail::assertNothingQueued();
});

it('blocks a buyer from resending a proof email', function (): void {
    [, $proof] = proofingQuote();
    Sanctum::actingAs($this->buyer);

    postJson("/api/proofs/{$proof->id}/resend")->assertStatus(403);
});

// ---- approve on behalf, attributed to the superadmin --------------------

it('lets a superadmin approve a proof on the buyer’s behalf, recorded as the superadmin', function (): void {
    [$quote, $proof] = proofingQuote();
    Sanctum::actingAs($this->superadmin);

    postJson("/api/proofs/{$proof->id}/decide", ['decision' => 'approve'])->assertOk();

    $proof->refresh();
    expect($proof->state->value)->toBe('APPROVED')
        // Attribution: the superadmin who acted, NOT the buyer.
        ->and($proof->approved_by)->toBe($this->superadmin->id)
        ->and($proof->approved_at)->not->toBeNull();
    // Buyer had accepted the price, so approval completes the pair.
    expect($quote->fresh()->state->value)->toBe('PROOF_APPROVED');
});
