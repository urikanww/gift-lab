<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Catalogue\ScrapedCatalogueService;
use App\Services\Scraper\HttpLazadaAffiliateClient;
use App\Services\Scraper\HttpShopeeAffiliateClient;
use Illuminate\Console\Command;

/**
 * Pull real UV-blank candidates from a marketplace affiliate feed (ToS-clean
 * product APIs - see HttpShopeeAffiliateClient / HttpLazadaAffiliateClient)
 * into the SCRAPED_UV admin gate. The feeds supply name/price/image/link;
 * dimensions, weight and printability are completed by staff in the gate, so
 * ingested items land as CANNOT_PUBLISH with concrete reason tags rather
 * than publishing raw.
 *
 *   php artisan catalogue:pull-uv "ceramic mug" --count=10
 *   php artisan catalogue:pull-uv "tumbler" --source=lazada
 */
class PullShopeeCatalogue extends Command
{
    protected $signature = 'catalogue:pull-uv
        {keyword : Search term, e.g. "ceramic mug"}
        {--count=10 : Listings to ingest}
        {--source=shopee : Feed to search: shopee or lazada}';

    protected $description = 'Search a marketplace affiliate feed and ingest UV-blank candidates into the admin gate.';

    public function handle(
        HttpShopeeAffiliateClient $shopee,
        HttpLazadaAffiliateClient $lazada,
        ScrapedCatalogueService $service,
    ): int {
        $source = strtolower((string) $this->option('source'));
        $keyword = (string) $this->argument('keyword');
        $count = max(1, (int) $this->option('count'));

        if (! in_array($source, ['shopee', 'lazada'], true)) {
            $this->error("Unknown --source \"{$source}\" (use shopee or lazada).");

            return self::FAILURE;
        }

        $listings = $source === 'shopee'
            ? $this->searchShopee($shopee, $keyword, $count)
            : $this->searchLazada($lazada, $keyword, $count);

        if ($listings === null) {
            // Missing credentials - already reported.
            return self::FAILURE;
        }

        if ($listings === []) {
            $this->warn("No results for \"{$keyword}\".");

            return self::SUCCESS;
        }

        $ingested = 0;

        foreach ($listings as $data) {
            try {
                $product = $service->ingest($data);
                $ingested++;
                $this->info("  ok    {$data->sourceProductId} {$data->name} → {$product->publish_state->value}");
            } catch (\Throwable $e) {
                report($e);
                $this->error("  fail  {$data->sourceProductId}: {$e->getMessage()}");
            }
        }

        $this->info("Ingested {$ingested} of ".count($listings)." listing(s) for \"{$keyword}\" - complete dimensions/printability in the admin gate.");

        return self::SUCCESS;
    }

    /**
     * @return array<int, \App\Services\Scraper\ScrapedProductData>|null
     */
    private function searchShopee(HttpShopeeAffiliateClient $client, string $keyword, int $count): ?array
    {
        if (! config('services.shopee_affiliate.app_id') || ! config('services.shopee_affiliate.secret')) {
            $this->error('SHOPEE_AFFILIATE_APP_ID / SHOPEE_AFFILIATE_SECRET are not configured.');

            return null;
        }

        return $client->search($keyword, $count);
    }

    /**
     * @return array<int, \App\Services\Scraper\ScrapedProductData>|null
     */
    private function searchLazada(HttpLazadaAffiliateClient $client, string $keyword, int $count): ?array
    {
        if (! config('services.lazada_affiliate.app_key') || ! config('services.lazada_affiliate.secret')) {
            $this->error('LAZADA_AFFILIATE_APP_KEY / LAZADA_AFFILIATE_SECRET are not configured.');

            return null;
        }

        return $client->search($keyword, $count);
    }
}
