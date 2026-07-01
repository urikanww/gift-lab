<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProductClass;
use App\Models\Product;
use App\Services\Catalogue\ScrapedCatalogueService;
use Illuminate\Console\Command;

/**
 * Daily re-sync of the scraped-UV catalogue (spec 6.4): re-fetch each item,
 * update indicative fields, and pull items from public on >threshold price
 * drift or dead source. Scheduled in routes/console.php; a scrape failure
 * degrades only the scraped catalogue and never blocks the core flow.
 */
class ResyncScrapedCatalogue extends Command
{
    protected $signature = 'catalogue:resync-scraped';

    protected $description = 'Re-sync scraped-UV products and detect price drift / dead sources.';

    public function handle(ScrapedCatalogueService $service): int
    {
        $count = 0;

        Product::query()
            ->where('class', ProductClass::ScrapedUv->value)
            ->chunkById(100, function ($products) use ($service, &$count): void {
                foreach ($products as $product) {
                    try {
                        $service->resync($product);
                        $count++;
                    } catch (\Throwable $e) {
                        // Isolate per-item failure so one bad listing never
                        // stalls the batch or the core flow.
                        report($e);
                    }
                }
            });

        $this->info("Re-synced {$count} scraped-UV product(s).");

        return self::SUCCESS;
    }
}
