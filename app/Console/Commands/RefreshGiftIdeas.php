<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Controllers\GiftIdeasController;
use App\Models\GiftIdeaFeature;
use App\Services\Scraper\Contracts\ScraperClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Daily refresh of the public gift-ideas features: re-fetch each item, update its
 * indicative price, and prune (soft-delete) any whose source went dead - so the
 * public page never shows stale prices or broken affiliate links.
 */
class RefreshGiftIdeas extends Command
{
    protected $signature = 'giftideas:refresh';

    protected $description = 'Refresh featured gift-idea prices and prune dead affiliate links.';

    public function handle(ScraperClient $scraper): int
    {
        $updated = 0;
        $pruned = 0;

        GiftIdeaFeature::query()->chunkById(100, function ($features) use ($scraper, &$updated, &$pruned): void {
            foreach ($features as $feature) {
                $data = $scraper->fetch($feature->source_product_id);
                if ($data === null || $data->sourceDead) {
                    $feature->delete();
                    $pruned++;

                    continue;
                }
                if ($data->price !== null) {
                    $feature->price = $data->price;
                    $feature->save();
                    $updated++;
                }
            }
        });

        Cache::forget(GiftIdeasController::CACHE_KEY);
        $this->info("Refreshed {$updated} feature(s), pruned {$pruned}.");

        return self::SUCCESS;
    }
}
