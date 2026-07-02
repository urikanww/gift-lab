<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Catalogue\CategoryClassifier;
use Illuminate\Console\Command;

/**
 * One-shot backfill: classify every product that has no marketplace category
 * yet (rows created before the category column existed, or raw-inserted).
 */
class BackfillProductCategories extends Command
{
    protected $signature = 'catalogue:categorize {--force : Re-classify ALL products, overwriting existing categories}';

    protected $description = 'Assign marketplace categories to products via the keyword classifier';

    public function handle(CategoryClassifier $classifier): int
    {
        $query = Product::withTrashed()
            ->when(! $this->option('force'), fn ($q) => $q->whereNull('category'));

        $count = 0;
        $query->chunkById(200, function ($products) use ($classifier, &$count): void {
            foreach ($products as $product) {
                $product->timestamps = false;
                $product->forceFill([
                    'category' => $classifier->classify((string) $product->name, $product->class),
                ])->saveQuietly();
                $count++;
            }
        });

        $this->info("Categorized {$count} product(s).");

        return self::SUCCESS;
    }
}
