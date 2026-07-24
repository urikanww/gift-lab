<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\LineItemState;
use App\Enums\ProofState;
use App\Enums\QuoteState;
use App\Models\Company;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Proof;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Explicit demo seeder for MANUAL browser verification and demos of the
 * per-line-item proof flow (branch feat/per-line-item-proofs). NOT wired into
 * DatabaseSeeder - run it on its own once the app is migrated:
 *
 *   php artisan db:seed --class=DemoProofOrderSeeder
 *
 * It builds a fixed, known-credential buyer plus two orders whose per-line
 * proofs sit in a spread of states, so the proof panels, the mixed-state order
 * card, the "all approved" callout and the buyer sign-off screens all have real
 * data to render.
 *
 * Idempotent: every run tears down the prior demo rows (matched by the fixed
 * company name / buyer email) before rebuilding, so re-running never duplicates.
 *
 * Artwork refs are plausible `artwork/demo-*.png` keys. The files will NOT exist
 * on a fresh disk - that is expected. Proof::hasStoredArtwork() still returns
 * true (the ref sits under the `artwork/` prefix), so the UI mints a signed
 * "View artwork" link; opening it 404s / shows the placeholder until a real file
 * is uploaded. That is the intended fallback for a demo without asset files.
 */
class DemoProofOrderSeeder extends Seeder
{
    private const COMPANY_NAME = 'Proof Demo Co';

    private const BUYER_EMAIL = 'demo-buyer@example.test';

    private const BUYER_PASSWORD = 'password';

    public function run(): void
    {
        [$quote1, $quote2] = DB::transaction(function (): array {
            $this->teardownPriorDemo();

            $company = Company::create([
                'name' => self::COMPANY_NAME,
                'registration_no' => '2026DEMO01Z',
                'billing_email' => 'billing@proof-demo.test',
                'phone' => '+65 6000 0000',
                'address' => '1 Demo Way, #01-01, Singapore 000000',
                'default_terms' => 'NET 30',
                'status' => 'ACTIVE',
            ]);

            $buyer = User::create([
                'company_id' => $company->id,
                'name' => 'Demo Buyer',
                'email' => self::BUYER_EMAIL,
                'email_verified_at' => now(),
                'password' => Hash::make(self::BUYER_PASSWORD),
                'role' => 'buyer',
            ]);

            $products = $this->products();

            $quote1 = $this->mixedStateOrder($company, $buyer, $products);
            $quote2 = $this->allApprovedOrder($company, $buyer, $products);

            return [$quote1, $quote2];
        });

        // $this->command is null when the seeder is newed up directly (tests);
        // only db:seed injects it. Guard so both entry points work.
        $this->command?->info('DemoProofOrderSeeder complete.');
        $this->command?->info('  Buyer login : '.self::BUYER_EMAIL.'  /  '.self::BUYER_PASSWORD);
        $this->command?->info('  Mixed-state order (PROOFING)         : '.$quote1->reference.'  ['.$quote1->state->value.']');
        $this->command?->info('  All-approved order (ARTWORK_APPROVED): '.$quote2->reference.'  ['.$quote2->state->value.']');
        $this->command?->info('  Note: artwork/demo-*.png files are not on disk - "View artwork" links 404 to the placeholder (expected).');
    }

    /**
     * Three CORE products carrying image_url so proof composites / thumbnails
     * have a product photo. The image keys reuse the CoreCatalogueSeeder photo
     * set so they resolve if that seeder's assets are present; otherwise the UI
     * falls back gracefully.
     *
     * @return array{cap: Product, mug: Product, tote: Product}
     */
    private function products(): array
    {
        return [
            'cap' => $this->product('Baseball Cap', 'apparel', 6.50, 'products/core-8.jpg'),
            'mug' => $this->product('Ceramic Mug', 'drinkware', 3.20, 'products/core-1.jpg'),
            'tote' => $this->product('Tote Bag', 'bags', 2.10, 'products/core-3.jpg'),
        ];
    }

    private function product(string $name, string $category, float $baseCost, string $imageKey): Product
    {
        return Product::create([
            'name' => $name,
            'description' => $name.' - demo blank, decorated via UV print.',
            'class' => 'CORE',
            'category' => $category,
            'base_cost' => $baseCost,
            'currency' => 'SGD',
            'dimensions' => ['l' => 100, 'w' => 80, 'h' => 90, 'unit' => 'mm'],
            'weight' => 250,
            'print_method' => 'UV',
            'publish_state' => 'PUBLISHED',
            'stock_mode' => 'STOCKED',
            'image_url' => url('storage/'.$imageKey),
            'is_printable' => true,
        ]);
    }

    /**
     * A three-line customized order in PROOFING with each line's proof lineage in
     * a different state:
     *   - Cap : latest APPROVED (v2), with a v1 CHANGES_REQUESTED before it.
     *   - Mug : latest SENT (awaiting buyer).
     *   - Tote: latest CHANGES_REQUESTED (buyer bounced it back with a note).
     * Line 2 being SENT is what keeps the rollup on PROOFING.
     *
     * @param  array{cap: Product, mug: Product, tote: Product}  $products
     */
    private function mixedStateOrder(Company $company, User $buyer, array $products): Quote
    {
        // Created directly in PROOFING so recomputeProofState() below is a
        // confirming no-op - the seeder never fires a state transition, and so
        // never triggers the milestone email / broadcast side effects that a
        // real staff action would (MAIL_MAILER can be smtp in this env).
        $quote = Quote::create([
            'company_id' => $company->id,
            'state' => QuoteState::Proofing->value,
            'currency' => 'SGD',
            'subtotal' => 0,
            'delivery' => 0,
            'total' => 0,
            'price_snapshot_at' => now(),
            'accepted_at' => now(),
            'accepted_by' => $buyer->id,
            'notes' => 'Demo order: per-line proofs in mixed states (approved / sent / changes-requested).',
            'created_by' => $buyer->id,
        ]);

        $cap = $this->line($quote, $products['cap'], 100, 8.50, 'artwork/demo-cap.png');
        $mug = $this->line($quote, $products['mug'], 250, 4.20, 'artwork/demo-mug.png');
        $tote = $this->line($quote, $products['tote'], 300, 3.10, 'artwork/demo-tote.png');

        // Cap: history then approval. v1 was bounced, v2 is the signed-off art.
        $this->proof($cap, 1, ProofState::ChangesRequested, notes: 'Please enlarge the logo and use the Pantone brand blue.');
        $this->proof($cap, 2, ProofState::Approved, approvedBy: $buyer->id);

        // Mug: single version, out with the buyer for a decision.
        $this->proof($mug, 1, ProofState::Sent);

        // Tote: single version, buyer sent it back for changes.
        $this->proof($tote, 1, ProofState::ChangesRequested, notes: 'Text is too close to the seam - shift the artwork up 15mm.');

        $this->retotal($quote);

        // Derive the order state from the per-line proofs. With line 2 SENT this
        // confirms PROOFING (state already matches, so this is a safe no-op).
        $quote->refresh();
        $quote->recomputeProofState();
        $quote->refresh();

        return $quote;
    }

    /**
     * A second order with every line's latest proof APPROVED, resting in
     * ARTWORK_APPROVED - drives the "all approved" callout + notification panel.
     *
     * @param  array{cap: Product, mug: Product, tote: Product}  $products
     */
    private function allApprovedOrder(Company $company, User $buyer, array $products): Quote
    {
        // accepted_at is null: with all proofs approved the artwork-first rollup
        // lands on ARTWORK_APPROVED (price not yet agreed). Created directly in
        // that state so recomputeProofState() stays a no-op (no milestone email).
        $quote = Quote::create([
            'company_id' => $company->id,
            'state' => QuoteState::ArtworkApproved->value,
            'currency' => 'SGD',
            'subtotal' => 0,
            'delivery' => 0,
            'total' => 0,
            'price_snapshot_at' => now(),
            'notes' => 'Demo order: all lines artwork-approved.',
            'created_by' => $buyer->id,
        ]);

        $cap = $this->line($quote, $products['cap'], 50, 8.50, 'artwork/demo-cap-2.png');
        $mug = $this->line($quote, $products['mug'], 120, 4.20, 'artwork/demo-mug-2.png');

        $this->proof($cap, 1, ProofState::Approved, approvedBy: $buyer->id);
        $this->proof($mug, 1, ProofState::Approved, approvedBy: $buyer->id);

        $this->retotal($quote);

        $quote->refresh();
        $quote->recomputeProofState();
        $quote->refresh();

        return $quote;
    }

    private function line(Quote $quote, Product $product, int $qty, float $unitPrice, string $artworkRef): LineItem
    {
        return LineItem::create([
            'quote_id' => $quote->id,
            'product_id' => $product->id,
            'qty' => $qty,
            'unit_price' => $unitPrice,
            'currency' => 'SGD',
            // mode set => needsProof() true, so the line counts in the rollup.
            'customization' => ['mode' => 'designer', 'artwork_ref' => $artworkRef],
            'line_state' => LineItemState::Pending->value,
            'lead_time_days' => 7,
        ]);
    }

    private function proof(
        LineItem $line,
        int $version,
        ProofState $state,
        ?int $approvedBy = null,
        ?string $notes = null,
    ): Proof {
        return Proof::create([
            'quote_id' => $line->quote_id,
            'line_item_id' => $line->id,
            'version' => $version,
            'artwork_version_ref' => $line->customization['artwork_ref'] ?? 'artwork/demo.png',
            'state' => $state->value,
            'approved_by' => $state === ProofState::Approved ? $approvedBy : null,
            'approved_at' => $state === ProofState::Approved ? now() : null,
            'notes' => $notes,
        ]);
    }

    /** Sum the lines into the quote total so the order card shows real money. */
    private function retotal(Quote $quote): void
    {
        $subtotal = $quote->lineItems()->get()->reduce(
            fn (string $carry, LineItem $line): string => bcadd($carry, $line->lineTotal(), 2),
            '0.00',
        );

        $quote->update([
            'subtotal' => $subtotal,
            'delivery' => '0.00',
            'total' => $subtotal,
        ]);
    }

    /**
     * Remove any prior demo rows so a re-run rebuilds cleanly. Quotes are
     * force-deleted first: the DB-level cascadeOnDelete then hard-removes their
     * line items and proofs, clearing the FK path before the products go.
     */
    private function teardownPriorDemo(): void
    {
        $companyIds = Company::withTrashed()
            ->where('name', self::COMPANY_NAME)
            ->pluck('id');

        if ($companyIds->isNotEmpty()) {
            Quote::withTrashed()
                ->whereIn('company_id', $companyIds)
                ->get()
                ->each
                ->forceDelete();
        }

        Product::withTrashed()
            ->whereIn('name', ['Baseball Cap', 'Ceramic Mug', 'Tote Bag'])
            ->where('description', 'like', '%demo blank%')
            ->get()
            ->each
            ->forceDelete();

        User::withTrashed()->where('email', self::BUYER_EMAIL)->forceDelete();
        Company::withTrashed()->whereIn('id', $companyIds)->forceDelete();
    }
}
