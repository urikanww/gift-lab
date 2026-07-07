<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the dynamic pricing configuration read by the quote engine at quote
 * time. No margins/fees are hardcoded in application code (spec principle 5);
 * these are editable defaults that the superadmin dashboard overrides.
 *
 * Uses the DB facade (no Eloquent model dependency) so it runs immediately
 * after migrations, before the Phase 2 model layer exists.
 */
class PricingConfigSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $rows = [
            // group, key, value (json-encoded), label, is_money
            ['margin', 'default_pct', 50, 'Default gross margin %', false],
            ['margin', 'floor_pct', 12, 'Hard margin floor % (amendments cannot price below landed cost + this)', false],
            ['fee', 'customization_flat', 8.00, 'Flat customization fee per line', true],
            ['fee', 'customization_per_unit', 0.00, 'Per-unit customization fee (repeated work, e.g. embossed personalisation)', true],
            ['fee', 'customization_by_size', ['S' => 0.00, 'M' => 0.40, 'L' => 0.90], 'Per-unit logo surcharge by size band (S/M/L)', true],
            ['fee', 'setup_fee', 25.00, 'One-off artwork setup fee per quote', true],
            ['print_cost', 'per_unit', ['UV' => 1.50, 'FDM' => 3.00, 'RESIN' => 5.00], 'Per-unit print cost by method', true],
            // MODEL_3D landed-cost inputs (filament + machine time). minutes_per_gram
            // is a proxy until a slicer integration supplies measured print times.
            ['print_cost', 'filament_per_gram', 0.05, 'Filament cost per gram', true],
            ['print_cost', 'minutes_per_gram', 2.0, 'Estimated print minutes per gram', false],
            ['print_cost', 'machine_rate_per_min', 0.08, 'Machine time rate per minute', true],
            // Deadline-aware delivery estimate inputs (queue-depth aware).
            ['lead_time', 'production_days', ['UV' => 3, '3D' => 5], 'Base production days by track', false],
            ['lead_time', 'daily_capacity', 8, 'Jobs the floor clears per day (queue-delay divisor)', false],
            ['lead_time', 'dispatch_days', 2, 'Shipping transit days added to production', false],
            ['lead_time', 'buffer_days', 3, 'Padding added for the upper bound of the delivery window', false],
            ['lead_time', 'rush_shave_days', 2, 'Days a rush order shaves off the earliest date (0 = rush off)', false],
            ['lead_time', 'rush_fee', 40.00, 'Flat rush fee', true],
            ['threshold', 'bulk_qty', 50, 'Quantity at/above which bulk pricing applies', false],
            ['threshold', 'bulk_discount_pct', 10, 'Discount % applied at bulk quantity', false],
            ['delivery', 'table', [
                ['max_weight_g' => 1000, 'price' => 5.00],
                ['max_weight_g' => 5000, 'price' => 12.00],
                ['max_weight_g' => 20000, 'price' => 30.00],
                ['max_weight_g' => null, 'price' => 60.00],
            ], 'Delivery price by total shipment weight (grams)', true],
            ['config', 'pay_now_cutoff', ['mode' => 'quote_only', 'b2c_enabled' => false], 'Pay-now vs quote cutoff rule', false],
            // Phase 2 catalogue-breadth controls.
            ['catalogue', 'auto_publish', false, 'Auto-publish complete scraped/3D items', false],
            ['catalogue', 'drift_pct', 10, 'Price drift % that pulls a scraped item for re-review', false],
            // Trademark keyword blocklist (layer 1 of the IP screen; the LLM
            // screen is layer 2). CC licences do not clear trademarks.
            ['catalogue', 'ip_blocklist', ['pokemon', 'pikachu', 'disney', 'mickey', 'marvel', 'star wars', 'nintendo', 'mario', 'zelda', 'lego', 'hello kitty', 'harry potter', 'minion', 'batman', 'superman', 'groot', 'baby yoda', 'mandalorian'], 'IP/trademark keyword blocklist for 3D ingest', false],
            ['catalogue', 'price_jump_pct', 10, 'Re-check price jump % tolerated before PRICE_JUMPED', false],
            ['catalogue', 'browse_cap', 200, 'Max commercial-OK 3D models ingested per source per nightly popular-browse sweep (catalogue:discover-3d)', false],
        ];

        foreach ($rows as [$group, $key, $value, $label, $isMoney]) {
            // Insert-only: a deploy-time re-seed must never clobber values the
            // superadmin tuned in the dashboard (margins, auto-publish toggle,
            // keyword lists). New config keys are added; existing rows are
            // left untouched.
            $exists = DB::table('pricing_configs')
                ->where('group', $group)
                ->where('key', $key)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('pricing_configs')->insert([
                'group' => $group,
                'key' => $key,
                'value' => json_encode($value),
                'label' => $label,
                'is_money' => $isMoney,
                'currency' => 'SGD',
                'updated_at' => $now,
                'created_at' => $now,
            ]);
        }
    }
}
