<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds a starter set of CORE blank products with variant trees so a real B2B
 * order can flow end-to-end on the spine. SCRAPED_UV and MODEL_3D catalogue are
 * Phase 2 and intentionally not seeded here. All money in SGD.
 */
class CoreCatalogueSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        // [name, base_cost, print_method, dims(mm), weight(g), variants[[color,size,stock,delta]]]
        $catalogue = [
            ['Ceramic Mug 11oz', 3.20, 'UV', ['l' => 95, 'w' => 82, 'h' => 95], 320, [
                ['White', 'STD', 240, 0.00],
                ['Black', 'STD', 180, 0.50],
            ]],
            ['Stainless Tumbler 500ml', 6.80, 'UV', ['l' => 70, 'w' => 70, 'h' => 220], 260, [
                ['Silver', '500ml', 150, 0.00],
                ['Matte Black', '500ml', 120, 1.20],
            ]],
            ['Canvas Tote Bag', 2.10, 'UV', ['l' => 380, 'w' => 10, 'h' => 420], 140, [
                ['Natural', 'STD', 400, 0.00],
                ['Navy', 'STD', 220, 0.30],
            ]],
            ['Bamboo Coaster', 1.40, 'UV', ['l' => 100, 'w' => 100, 'h' => 8], 60, [
                ['Natural', 'Round', 500, 0.00],
                ['Natural', 'Square', 480, 0.00],
            ]],
            ['A5 Hardcover Notebook', 4.50, 'UV', ['l' => 148, 'w' => 15, 'h' => 210], 300, [
                ['Kraft', 'A5', 300, 0.00],
                ['Black', 'A5', 260, 0.40],
            ]],
            ['Ballpoint Pen (Metal)', 0.90, 'UV', ['l' => 140, 'w' => 12, 'h' => 12], 25, [
                ['Silver', 'STD', 1000, 0.00],
                ['Gold', 'STD', 600, 0.15],
            ]],
            ['Glass Water Bottle 600ml', 5.40, 'UV', ['l' => 72, 'w' => 72, 'h' => 240], 420, [
                ['Clear', '600ml', 180, 0.00],
            ]],
            ['Cotton T-Shirt', 3.80, 'UV', ['l' => 300, 'w' => 5, 'h' => 400], 180, [
                ['White', 'M', 260, 0.00],
                ['White', 'L', 240, 0.00],
                ['Black', 'M', 200, 0.60],
                ['Black', 'L', 190, 0.60],
            ]],
            ['Silicone Phone Grip', 0.70, 'UV', ['l' => 40, 'w' => 40, 'h' => 10], 15, [
                ['White', 'STD', 800, 0.00],
                ['Black', 'STD', 750, 0.00],
            ]],
            ['Enamel Keychain', 1.10, 'UV', ['l' => 50, 'w' => 30, 'h' => 4], 20, [
                ['Gold', 'STD', 500, 0.00],
                ['Silver', 'STD', 520, 0.00],
            ]],
        ];

        foreach ($catalogue as [$name, $baseCost, $method, $dims, $weight, $variants]) {
            $productId = DB::table('products')->insertGetId([
                'name' => $name,
                'description' => $name.' — blank core stock, decorate via UV print.',
                'class' => 'CORE',
                'base_cost' => $baseCost,
                'currency' => 'SGD',
                'dimensions' => json_encode($dims + ['unit' => 'mm']),
                'weight' => $weight,
                'print_method' => $method,
                'publish_state' => 'PUBLISHED',
                'stock_mode' => 'STOCKED',
                'is_printable' => true,
                'created_by' => null,
                'updated_at' => $now,
                'created_at' => $now,
            ]);

            foreach ($variants as $index => [$color, $size, $stock, $delta]) {
                DB::table('variants')->insert([
                    'product_id' => $productId,
                    'attributes' => json_encode(['color' => $color, 'size' => $size]),
                    'sku' => sprintf('CORE-%03d-%02d', $productId, $index + 1),
                    'stock_on_hand' => $stock,
                    'reorder_threshold' => 20,
                    'price_delta' => $delta,
                    'currency' => 'SGD',
                    'updated_at' => $now,
                    'created_at' => $now,
                ]);
            }
        }
    }
}
