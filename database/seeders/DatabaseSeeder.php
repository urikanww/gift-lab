<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Root seeder. Order matters: staff users and pricing config first (referenced
 * by later flows), then the CORE catalogue spine and starter filament stock.
 * SCRAPED_UV / MODEL_3D catalogue items flow in via the discovery commands.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AdminUserSeeder::class,
            PricingConfigSeeder::class,
            CoreCatalogueSeeder::class,
            FilamentSeeder::class,
        ]);
    }
}
