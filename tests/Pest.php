<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case bindings
|--------------------------------------------------------------------------
| Feature tests hit the full framework with a fresh in-memory SQLite schema.
| Unit tests exercise pure domain logic (enums / state machines) with no DB.
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');

/*
|--------------------------------------------------------------------------
| Shared expectations / helpers
|--------------------------------------------------------------------------
*/

/**
 * Seed the dynamic pricing configuration a Feature test needs before quoting.
 */
function seedPricing(): void
{
    (new Database\Seeders\PricingConfigSeeder())->run();
}
