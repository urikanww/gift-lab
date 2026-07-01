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
            ['margin', 'default_pct', 35, 'Default gross margin %', false],
            ['margin', 'floor_pct', 12, 'Hard margin floor % (amendments cannot price below landed cost + this)', false],
            ['fee', 'customization_flat', 8.00, 'Flat customization fee per line', true],
            ['fee', 'setup_fee', 25.00, 'One-off artwork setup fee per quote', true],
            ['print_cost', 'per_unit', ['UV' => 1.50, 'FDM' => 3.00, 'RESIN' => 5.00], 'Per-unit print cost by method', true],
            ['threshold', 'bulk_qty', 50, 'Quantity at/above which bulk pricing applies', false],
            ['threshold', 'bulk_discount_pct', 10, 'Discount % applied at bulk quantity', false],
            ['delivery', 'table', [
                ['max_weight_g' => 1000, 'price' => 5.00],
                ['max_weight_g' => 5000, 'price' => 12.00],
                ['max_weight_g' => 20000, 'price' => 30.00],
                ['max_weight_g' => null, 'price' => 60.00],
            ], 'Delivery price by total shipment weight (grams)', true],
            ['config', 'pay_now_cutoff', ['mode' => 'quote_only', 'b2c_enabled' => false], 'Pay-now vs quote cutoff rule', false],
        ];

        foreach ($rows as [$group, $key, $value, $label, $isMoney]) {
            DB::table('pricing_configs')->updateOrInsert(
                ['group' => $group, 'key' => $key],
                [
                    'value' => json_encode($value),
                    'label' => $label,
                    'is_money' => $isMoney,
                    'currency' => 'SGD',
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }
    }
}
