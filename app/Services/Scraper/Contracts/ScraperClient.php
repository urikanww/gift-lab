<?php

declare(strict_types=1);

namespace App\Services\Scraper\Contracts;

use App\Services\Scraper\ScrapedProductData;

/**
 * Fetches a marketplace listing for ingest / daily re-sync. Implementations must
 * never drive a consumer checkout (spec 7) - read-only ingest. A dead/removed
 * listing returns data with sourceDead=true (or null) so the caller can flip the
 * product to CANNOT_PUBLISH rather than blocking the core flow.
 */
interface ScraperClient
{
    public function fetch(string $sourceProductId): ?ScrapedProductData;
}
