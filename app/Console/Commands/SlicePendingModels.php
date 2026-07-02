<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProductClass;
use App\Models\Product;
use App\Services\Model3d\SlicerService;
use Illuminate\Console\Command;

/**
 * Nightly slicer sweep: measure every MODEL_3D item whose estimates are still
 * unverified. With a slicer configured this removes the manual verify click
 * entirely — an item flows discover → licence gate → file store → slicer →
 * (auto-publish, if enabled) with no human touch.
 */
class SlicePendingModels extends Command
{
    protected $signature = 'catalogue:slice-pending {--limit=50 : Max items to slice per run}';

    protected $description = 'Slice unverified MODEL_3D items for measured grams/print-minutes.';

    public function handle(SlicerService $slicer): int
    {
        if (! $slicer->isConfigured()) {
            $this->warn('SLICER_BINARY is not configured — manual estimate verification stays in effect.');

            return self::SUCCESS;
        }

        $measured = 0;
        $failed = 0;

        Product::query()
            ->where('class', ProductClass::Model3d->value)
            ->where('estimates_verified', false)
            ->limit(max(1, (int) $this->option('limit')))
            ->get()
            ->each(function (Product $product) use ($slicer, &$measured, &$failed): void {
                try {
                    $slicer->measure($product) ? $measured++ : $failed++;
                } catch (\Throwable $e) {
                    report($e);
                    $failed++;
                }
            });

        $this->info("Sliced {$measured} item(s); {$failed} skipped/failed.");

        return self::SUCCESS;
    }
}
