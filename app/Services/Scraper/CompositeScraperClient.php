<?php

declare(strict_types=1);

namespace App\Services\Scraper;

use App\Services\Scraper\Contracts\ScraperClient;

/**
 * Routes a fetch to the marketplace that owns the listing, by source-id
 * prefix: "lazada:{id}" → Lazada, everything else (including legacy
 * un-prefixed Shopee ids like "9_123") → Shopee. Keeps the daily resync
 * working across both feeds through the single ScraperClient binding.
 */
final class CompositeScraperClient implements ScraperClient
{
    public function __construct(
        private readonly ?ScraperClient $shopee,
        private readonly ?ScraperClient $lazada,
        private readonly ScraperClient $fallback,
    ) {
    }

    public function fetch(string $sourceProductId): ?ScrapedProductData
    {
        if (str_starts_with($sourceProductId, 'lazada:')) {
            return ($this->lazada ?? $this->fallback)->fetch($sourceProductId);
        }

        return ($this->shopee ?? $this->fallback)->fetch($sourceProductId);
    }
}
