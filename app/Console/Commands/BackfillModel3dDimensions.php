<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProductClass;
use App\Models\Product;
use App\Services\Model3d\Model3dCatalogueService;
use Illuminate\Console\Command;

/**
 * One-off/backstop backfill (audit B10): MODEL_3D items ingested before the
 * STL bounding-box pass carry `dimensions: null` even though the stored
 * geometry knows the answer. Reads each product's local model file and fills
 * in the missing footprint; explicitly set dimensions are never overwritten.
 */
class BackfillModel3dDimensions extends Command
{
    protected $signature = 'catalogue:backfill-3d-dimensions {--dry-run : Report what would change without saving}';

    protected $description = 'Fill missing MODEL_3D product dimensions from stored STL geometry';

    public function handle(Model3dCatalogueService $catalogue): int
    {
        $candidates = Product::query()
            ->where('class', ProductClass::Model3d->value)
            ->whereNull('dimensions')
            ->whereNotNull('model_file_ref')
            ->get();

        $filled = 0;

        foreach ($candidates as $product) {
            $catalogue->fillDimensionsFromModel($product);

            if ($product->dimensions === null) {
                continue;
            }

            $dims = $product->dimensions;
            $this->line(sprintf(
                '#%d %s -> %s x %s x %s mm',
                $product->id,
                $product->name,
                $dims['l'],
                $dims['w'],
                $dims['h'],
            ));

            if (! $this->option('dry-run')) {
                $product->save();
            }
            $filled++;
        }

        $this->info(sprintf(
            '%d of %d dimension-less MODEL_3D products %s.',
            $filled,
            $candidates->count(),
            $this->option('dry-run') ? 'would be backfilled' : 'backfilled',
        ));

        return self::SUCCESS;
    }
}
