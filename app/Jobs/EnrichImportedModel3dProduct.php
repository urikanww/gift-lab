<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Model3dSource;
use App\Models\Product;
use App\Services\Model3d\Model3dCatalogueService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Converge an imported MODEL_3D product onto the shared enrichment
 * (Model3dCatalogueService::enrichImportedProduct): link a Model3D provenance
 * row, run the IP screen, derive an STL from a MakerWorld .3mf (+ keep the .3mf
 * as the production file), mirror the thumbnail, and fill dimensions.
 *
 * QUEUED on purpose: 3mf->STL conversion is CPU-heavy (a large multi-object
 * project takes tens of seconds) and the thumbnail mirror makes an HTTP fetch -
 * neither may run inside the import request. The importer dispatches one job per
 * imported MODEL_3D product AFTER the row is committed.
 */
final class EnrichImportedModel3dProduct implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // The enrich upsert (Model3dCatalogueService::enrichImportedProduct) is
    // idempotent on (source, source_id) - re-running it links the same Model3D
    // row rather than duplicating it - so retrying a transient failure (a
    // thumbnail HTTP fetch blip, a momentary DB contention) is safe. 3
    // attempts with a short then longer backoff gives a transient fault time
    // to clear without hammering the source.
    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(
        public readonly int $productId,
        public readonly string $source,
    ) {}

    public function handle(Model3dCatalogueService $service): void
    {
        $product = Product::find($this->productId);
        if ($product === null) {
            return;
        }

        $source = Model3dSource::tryFrom($this->source) ?? Model3dSource::Owned;
        $service->enrichImportedProduct($product, $source);
    }

    /**
     * All retries exhausted. Log only - the global Queue::failing() hook
     * (AppServiceProvider::boot() -> StaffNotifier::jobFailed()) owns the one
     * staff alert; a per-job handler that also notified would double-alert.
     */
    public function failed(Throwable $e): void
    {
        Log::error('Enrich imported MODEL_3D product job failed after retries.', [
            'product_id' => $this->productId,
            'source' => $this->source,
            'error' => $e->getMessage(),
        ]);
    }
}
