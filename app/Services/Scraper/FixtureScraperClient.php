<?php

declare(strict_types=1);

namespace App\Services\Scraper;

use App\Services\Scraper\Contracts\ScraperClient;

/**
 * Default ScraperClient binding. Real marketplace ingest (Shopee/Lazada) is a
 * human/admin/contracted-supplier feed, not a bot checkout (spec 7), so the live
 * client is provisioned separately. This fixture serves an in-memory table so
 * the ingest → completeness → drift pipeline is fully exercisable now, and tests
 * can seed deterministic listings via with().
 */
final class FixtureScraperClient implements ScraperClient
{
    /** @var array<string, ScrapedProductData> */
    private array $listings = [];

    public function with(ScrapedProductData $data): self
    {
        $this->listings[$data->sourceProductId] = $data;

        return $this;
    }

    public function fetch(string $sourceProductId): ?ScrapedProductData
    {
        return $this->listings[$sourceProductId] ?? null;
    }
}
