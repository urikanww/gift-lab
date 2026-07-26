<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Filament;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Harness\Agents\BuyerAgent;
use Tests\Harness\Agents\StaffAgent;
use Tests\Harness\Agents\ValidatorAgent;
use Tests\Harness\Support\HarnessContext;

/**
 * Characterizes doc "blocker 4": does cancelling a procured MODEL_3D order
 * return the filament it consumed?
 *
 * Model3dProcurement::procure() (app/Services/Procurement/Model3dProcurement.php:64-65)
 * decrements Filament::qty_on_hand with a direct column write - no
 * StockMovement is ever recorded for a 3D line's consumption. QuoteService's
 * returnConsumedStock() (app/Services/QuoteService.php:834-860), which cancel()
 * relies on to give back consumed stock, only reverses StockMovement 'SALE'
 * rows and explicitly skips any line with variant === null (line 838-841) -
 * which every MODEL_3D line is, since it is sourced from a Filament row, not a
 * Variant. So even setting the skip aside, there is no ledger entry for it to
 * reverse. This test drives a real procured 3D order through cancel and
 * asserts whatever the filament balance actually does - it does not assume
 * the outcome up front.
 */
beforeEach(function (): void {
    Mail::fake();
    Storage::fake((string) config('filesystems.artwork_disk'));
    Storage::disk((string) config('filesystems.artwork_disk'))->put('artwork/logo-v1.png', 'fake-artwork-bytes');
    seedPricing();

    $this->company = Company::factory()->create();
    $this->buyer = User::factory()->create(['company_id' => $this->company->id, 'role' => 'buyer']);
    $this->staff = User::factory()->staffAdmin()->create();

    // Matches Model3dProcurementTest's model3dLine() fixture: a Filament row
    // keyed on material/color, and a MODEL_3D product drawing from it.
    $this->filament = Filament::create([
        'material' => 'PLA',
        'color' => 'Black',
        'qty_on_hand' => 1000,
        'reorder_threshold' => 100,
    ]);

    $this->product = Product::factory()->model3d()->create([
        'filament_material' => 'PLA',
        'filament_color' => 'Black',
        'est_grams' => 40,
        'base_cost' => 10,
    ]);

    $this->ctx = new HarnessContext($this, $this->staff, $this->buyer);
});

it('characterizes whether cancelling a procured 3D order restores its consumed filament', function (): void {
    $staff = new StaffAgent($this->ctx);
    $buyer = new BuyerAgent($this->ctx);
    $validator = new ValidatorAgent($this->ctx);

    // Filament on hand before this order ever touches it.
    $filamentBefore = (float) $this->filament->fresh()->qty_on_hand;
    expect($filamentBefore)->toBe(1000.0);

    // 1. Staff drafts the 3D line (customized, so it takes a proof - same
    // route the happy path uses) and runs it to PROOF_APPROVED.
    $staff->createDraft($this->company->id, [
        [
            'product_id' => $this->product->id,
            'variant_id' => null,
            'qty' => 5,
            'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/logo-v1.png'],
        ],
    ]);
    $staff->send();
    $buyer->accept();

    $line = $this->ctx->quote->fresh('lineItems')->lineItems->first();
    $proof = $staff->stageProof($line, 'artwork/logo-v1.png');
    $staff->sendProofs();
    $buyer->approveProof($proof['id']);
    expect($this->ctx->quote->fresh()->state->value)->toBe('PROOF_APPROVED');

    // 2. Invoice (-> CONFIRMED) then procure. Procurement is what actually
    // consumes filament (Model3dProcurement::procure()).
    $staff->issueInvoice('PO-3D-CANCEL-1');
    expect($this->ctx->quote->fresh()->state->value)->toBe('CONFIRMED');

    $staff->procure();
    expect($this->ctx->quote->fresh()->state->value)->toBe('PROCURING');

    // Filament consumed: 1000 - (40 grams/unit * 5 units) = 800.
    $filamentAfterProcure = (float) $this->filament->fresh()->qty_on_hand;
    expect($filamentAfterProcure)->toBe(800.0);

    // 3. Cancel the procured order (legal from PROCURING) and observe the
    // filament balance afterward.
    $staff->cancel('customer changed mind');
    expect($this->ctx->quote->fresh()->state->value)->toBe('CANCELLED');

    $filamentAfter = (float) $this->filament->fresh()->qty_on_hand;

    // OBSERVED REALITY: blocker 4 is live. Cancel does not restore filament -
    // the 800g consumed by procurement stays consumed. QuoteService::cancel()
    // only ever reverses StockMovement 'SALE' rows for variant-backed lines;
    // a MODEL_3D line has no variant and no StockMovement was ever written for
    // its filament draw, so there is nothing for returnConsumedStock() to give
    // back.
    expect($filamentAfter)->toBeLessThan($filamentBefore)
        ->and($filamentAfter)->toBe($filamentAfterProcure);

    // The variant-backed ledger invariant still holds - it simply has nothing
    // to check here, since the 3D line carries no variant and therefore no
    // StockMovement rows at all.
    $validator->check('LEDGER_BALANCED_AFTER_CANCEL');
    expect($validator->violations())->toBeEmpty();
});
