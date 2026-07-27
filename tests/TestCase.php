<?php

declare(strict_types=1);

namespace Tests;

use App\Models\PricingConfig;
use App\Support\OutboundUrlGuard;
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

        // Default DNS stub for OutboundUrlGuard (SSRF guard on staff-supplied
        // capture URLs): resolve any non-IP-literal test hostname to a fixed
        // public address so ListingCapture/AdminBlankCapture tests exercise
        // the happy path without a real DNS lookup - real DNS has no place in
        // the suite (slow, flaky, network-dependent). Tests that specifically
        // exercise the guard's blocking behaviour override this per-test.
        OutboundUrlGuard::$resolver = fn (string $host): array => ['93.184.216.34'];
    }
}
