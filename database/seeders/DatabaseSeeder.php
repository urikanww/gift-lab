<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Root seeder. Order matters: staff users and pricing config first (referenced
 * by later flows), then starter filament stock. No products are seeded - the
 * catalogue is populated ONLY from real sources via the discovery commands
 * (catalogue:pull-uv, catalogue:pull-3d / catalogue:discover-3d). The hardcoded
 * CORE starter catalogue was test data and is intentionally not seeded.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AdminUserSeeder::class,
            PricingConfigSeeder::class,
            FilamentSeeder::class,
        ]);
    }
}
