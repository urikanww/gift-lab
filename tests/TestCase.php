<?php

declare(strict_types=1);

namespace Tests;

use App\Models\PricingConfig;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Isolate PricingConfig reads between tests. Both the shared cache (array
        // driver) and the per-request static memo persist for the whole process,
        // so a config value read in one test would otherwise leak into the next.
        Cache::flush();
        PricingConfig::flushMemo();
    }
}
