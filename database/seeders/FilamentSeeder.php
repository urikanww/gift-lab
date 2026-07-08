<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Filament;
use Illuminate\Database\Seeder;

/**
 * Starter filament inventory: the spools 3D procurement decrements against.
 * A colour offered in the designer without a matching spool row here goes
 * QTY_SHORT at procurement, so keep this list and the designer's colour
 * options aligned. Create-only - a re-seed must never reset qty_on_hand
 * (that column is live stock, mutated by procurement and receiving).
 */
class FilamentSeeder extends Seeder
{
    public function run(): void
    {
        $spools = [
            // material, color, grams on hand, reorder threshold (grams)
            ['PLA', 'Black', 1000, 200],
            ['PLA', 'White', 1000, 200],
            ['PLA', 'Grey', 1000, 200],
        ];

        foreach ($spools as [$material, $color, $grams, $threshold]) {
            Filament::firstOrCreate(
                ['material' => $material, 'color' => $color],
                ['qty_on_hand' => $grams, 'reorder_threshold' => $threshold],
            );
        }
    }
}
