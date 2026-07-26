<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Harness\Agents\BuyerAgent;
use Tests\Harness\Agents\StaffAgent;
use Tests\Harness\Agents\ValidatorAgent;
use Tests\Harness\Support\HarnessContext;

beforeEach(function (): void {
    Mail::fake();
    seedPricing();
    // Fake the artwork disk and stage the refs the flow submits so
    // StoreQuoteRequest / StoreProofRequest existence validation passes.
    Storage::fake(config('filesystems.artwork_disk'));
    Storage::disk(config('filesystems.artwork_disk'))->put('artwork/v1.png', 'x');
    Storage::disk(config('filesystems.artwork_disk'))->put('artwork/v2.png', 'x');

    $this->company = Company::factory()->create();
    $this->buyer = User::factory()->create(['company_id' => $this->company->id, 'role' => 'buyer']);
    $this->staff = User::factory()->staffAdmin()->create();
    $this->product = Product::factory()->create(['base_cost' => 10, 'print_method' => 'UV', 'publish_state' => 'PUBLISHED', 'class' => 'CORE']);
    Variant::factory()->create(['product_id' => $this->product->id, 'stock_on_hand' => 100]);
    $this->ctx = new HarnessContext($this, $this->staff, $this->buyer);
});

it('recovers from CHANGES_REQUESTED through a revised proof (blocker 1 regression)', function (): void {
    $staff = new StaffAgent($this->ctx);
    $buyer = new BuyerAgent($this->ctx);
    $validator = new ValidatorAgent($this->ctx);

    $staff->createDraft($this->company->id, [
        [
            'product_id' => $this->product->id,
            'variant_id' => null,
            'qty' => 4,
            'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/v1.png'],
        ],
    ]);
    $staff->send();
    $buyer->accept();

    $line = $this->ctx->quote->fresh('lineItems')->lineItems->first();

    // Round 1: buyer requests changes -> order rolls to CHANGES_REQUESTED.
    $v1 = $staff->stageProof($line, 'artwork/v1.png');
    $staff->sendProofs();
    $buyer->requestChanges($v1['id'], 'Logo too small');
    expect($this->ctx->quote->fresh()->state->value)->toBe('CHANGES_REQUESTED');

    // Round 2: staff reissue a revised proof; buyer approves -> order recovers.
    $v2 = $staff->stageProof($line, 'artwork/v2.png');
    $staff->sendProofs();
    $buyer->approveProof($v2['id']);

    // NO_STUCK_ORDER, scenario form: the order left CHANGES_REQUESTED and
    // reached an approved state rather than dying.
    expect($this->ctx->quote->fresh()->state->value)->toBe('PROOF_APPROVED');
    expect($validator->violations())->toBeEmpty();
});
