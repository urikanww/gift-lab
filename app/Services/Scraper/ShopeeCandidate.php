<?php

declare(strict_types=1);

namespace App\Services\Scraper;

/**
 * A ranked recommender candidate from the Shopee Affiliate feed. Carries BOTH
 * links deliberately: productLink (plain, for buy-per-order procurement) and
 * offerLink (affiliate tracking, for the public gift-ideas page ONLY). Never
 * use offerLink for our own checkout (self-referral).
 */
final readonly class ShopeeCandidate
{
    public function __construct(
        public string $sourceProductId,
        public string $name,
        public ?float $price,
        public string $currency,
        public ?string $imageUrl,
        public string $productLink,
        public string $offerLink,
        public int $sales,
        public ?float $ratingStar,
        public ?string $shopName,
        /** Max commission rate as a fraction, e.g. 0.18 = 18%. */
        public ?float $commissionRate = null,
    ) {}
}
