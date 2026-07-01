<?php

declare(strict_types=1);

namespace App\Services\Scraper;

/**
 * Normalised result of scraping/ingesting one marketplace listing. All fields
 * are non-authoritative estimates (spec principle 3); the procurement-time
 * re-check is the real read. Any field may be null when the scrape is partial —
 * the completeness gate decides publishability from what is present.
 */
final readonly class ScrapedProductData
{
    /**
     * @param  array{l?: float|int, w?: float|int, h?: float|int, unit?: string}|null  $dimensions
     */
    public function __construct(
        public string $sourceProductId,
        public string $sourceUrl,
        public ?string $name,
        public ?float $price,
        public ?array $dimensions,
        public ?float $weight,
        public ?int $stockEstimate,
        public ?string $imageUrl,
        public bool $printable,
        public bool $sourceDead = false,
    ) {
    }
}
