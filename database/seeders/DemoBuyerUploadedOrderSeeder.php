<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\LineItemState;
use App\Enums\QuoteState;
use App\Models\Company;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Quote;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * Demo seeder for the buyer "Upload finished look" → staff-designed proof flow.
 * NOT wired into DatabaseSeeder - run it on its own:
 *
 *   php artisan db:seed --class=DemoBuyerUploadedOrderSeeder
 *
 * It builds:
 *   - a PUBLISHED product (+ a stocked variant) so the buyer can also walk the
 *     designer's "Upload finished look" path themselves; and
 *   - a DRAFT order carrying ONE buyer_uploaded line: the buyer briefed the team
 *     with two real reference images + placement notes instead of designing it.
 *
 * Real PNG files are written to the artwork disk so the staff BuyerBrief panel's
 * thumbnails actually resolve (signed preview URLs), not just refs on paper.
 *
 * With the order in DRAFT and its line's proof not yet staged:
 *   - STAFF (open /orders/{ref}) see the "Prepare proofs" panel with the buyer's
 *     brief (reference thumbnails + notes) and can stage the first proof.
 *   - the BUYER (login below) sees the "Design in progress — proof coming" notice.
 *
 * Idempotent: every run tears down the prior demo rows (matched by the fixed
 * company name / buyer email / product name) before rebuilding.
 */
class DemoBuyerUploadedOrderSeeder extends Seeder
{
    private const COMPANY_NAME = 'Finished Look Demo Co';

    private const BUYER_EMAIL = 'demo-finishedlook@example.test';

    private const BUYER_PASSWORD = 'password';

    private const PRODUCT_NAME = 'Demo Enamel Pin (Upload-a-look)';

    /** @var array<int, string> */
    private const REFERENCE_REFS = [
        'artwork/demo-finishedlook-ref-1.png',
        'artwork/demo-finishedlook-ref-2.png',
    ];

    public function run(): void
    {
        $quote = DB::transaction(function (): Quote {
            $this->teardownPriorDemo();
            $this->writeReferenceImages();

            $company = Company::create([
                'name' => self::COMPANY_NAME,
                'registration_no' => '2026FLDEMO1Z',
                'billing_email' => 'billing@finishedlook-demo.test',
                'phone' => '+65 6000 0001',
                'address' => '1 Demo Way, #02-02, Singapore 000001',
                'default_terms' => 'NET 30',
                'status' => 'ACTIVE',
            ]);

            $buyer = User::create([
                'company_id' => $company->id,
                'name' => 'Finished Look Buyer',
                'email' => self::BUYER_EMAIL,
                'email_verified_at' => now(),
                'password' => Hash::make(self::BUYER_PASSWORD),
                'role' => 'buyer',
            ]);

            $product = Product::create([
                'name' => self::PRODUCT_NAME,
                'description' => 'Demo blank for the "Upload finished look" flow — decorated via UV print.',
                'class' => 'CORE',
                'category' => 'accessories',
                'base_cost' => 4.00,
                'currency' => 'SGD',
                'dimensions' => ['l' => 50, 'w' => 50, 'h' => 5, 'unit' => 'mm'],
                'weight' => 40,
                'print_method' => 'UV',
                'publish_state' => 'PUBLISHED',
                'stock_mode' => 'STOCKED',
                'is_printable' => true,
            ]);

            // A stocked variant so the product is quotable from the storefront
            // designer too (CORE items need ≥1 variant). Demo-only direct write.
            $variant = Variant::create([
                'product_id' => $product->id,
                'attributes' => ['finish' => 'Gloss'],
                'sku' => 'DEMO-FL-GLOSS',
                'stock_on_hand' => 500,
                'reorder_threshold' => 50,
                'price_delta' => 0,
                'currency' => 'SGD',
            ]);

            // DRAFT order with the buyer's brief. No proof staged yet: this is the
            // exact post-checkout state a real "Upload finished look" order lands
            // in, and what both new surfaces (staff brief + buyer notice) key off.
            $quote = Quote::create([
                'company_id' => $company->id,
                'state' => QuoteState::Draft->value,
                'currency' => 'SGD',
                'subtotal' => 0,
                'delivery' => 0,
                'total' => 0,
                'price_snapshot_at' => now(),
                'notes' => 'Demo order: buyer uploaded a finished-look brief for the team to design.',
                'created_by' => $buyer->id,
            ]);

            // Ship-to so the order can go all the way to a NinjaVan booking
            // without a manual fix-up. Pickup comes from the courier config
            // (CourierConfigSeeder), so a real shipment has both legs.
            $quote->shippingAddress()->create([
                'recipient_name' => 'Finished Look Buyer',
                'phone' => '+6591234567',
                'email' => self::BUYER_EMAIL,
                'line1' => '1 Marina Boulevard, #20-01',
                'city' => 'Singapore',
                'postal_code' => '018989',
                'country' => 'SG',
            ]);

            LineItem::create([
                'quote_id' => $quote->id,
                'product_id' => $product->id,
                'variant_id' => $variant->id,
                'qty' => 100,
                'unit_price' => 6.50,
                'currency' => 'SGD',
                'customization' => [
                    'mode' => 'buyer_uploaded',
                    'reference_refs' => self::REFERENCE_REFS,
                    'placement_notes' => "Centre the mascot on the front face.\nUse our brand teal (#0FB5AE), gold outline. Match the reference proportions.",
                ],
                'line_state' => LineItemState::Pending->value,
                'lead_time_days' => 7,
            ]);

            $this->retotal($quote);

            return $quote->fresh();
        });

        $this->command?->info('DemoBuyerUploadedOrderSeeder complete.');
        $this->command?->info('  Buyer login : '.self::BUYER_EMAIL.'  /  '.self::BUYER_PASSWORD);
        $this->command?->info('  Product     : '.self::PRODUCT_NAME.' (PUBLISHED)');
        $this->command?->info('  Demo order  : '.$quote->reference.'  ['.$quote->state->value.']  — one buyer_uploaded line, no proof yet.');
        $this->command?->info('  Staff: open /orders/'.$quote->reference.' → "Prepare proofs" shows the buyer brief (2 references + notes).');
        $this->command?->info('  Buyer: open the same order → "Design in progress — proof coming" notice.');
    }

    /**
     * Write two distinct, real PNGs to the artwork disk so the BuyerBrief
     * thumbnails resolve. GD when available (labelled swatches); otherwise a
     * tiny embedded solid-colour PNG so the demo never depends on an extension.
     */
    private function writeReferenceImages(): void
    {
        $disk = Storage::disk((string) config('filesystems.artwork_disk'));

        $swatches = [
            [self::REFERENCE_REFS[0], 15, 181, 174, 'REFERENCE 1'],
            [self::REFERENCE_REFS[1], 212, 175, 55, 'REFERENCE 2'],
        ];

        foreach ($swatches as [$ref, $r, $g, $b, $label]) {
            $disk->put($ref, $this->pngSwatch($r, $g, $b, $label));
        }
    }

    private function pngSwatch(int $r, int $g, int $b, string $label): string
    {
        if (function_exists('imagecreatetruecolor')) {
            $img = imagecreatetruecolor(400, 300);
            $bg = imagecolorallocate($img, $r, $g, $b);
            $fg = imagecolorallocate($img, 255, 255, 255);
            imagefilledrectangle($img, 0, 0, 400, 300, $bg);
            imagestring($img, 5, 20, 140, $label, $fg);
            ob_start();
            imagepng($img);
            $bytes = (string) ob_get_clean();
            imagedestroy($img);

            return $bytes;
        }

        // Fallback: a valid 1x1 PNG (colour is not preserved, but it renders).
        return (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        );
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

    /** Remove any prior demo rows so a re-run rebuilds cleanly. */
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
            ->where('name', self::PRODUCT_NAME)
            ->get()
            ->each
            ->forceDelete();

        User::withTrashed()->where('email', self::BUYER_EMAIL)->forceDelete();
        Company::withTrashed()->whereIn('id', $companyIds)->forceDelete();

        $disk = Storage::disk((string) config('filesystems.artwork_disk'));
        foreach (self::REFERENCE_REFS as $ref) {
            $disk->delete($ref);
        }
    }
}
