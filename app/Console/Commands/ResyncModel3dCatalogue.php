<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProductClass;
use App\Enums\PublishState;
use App\Models\Product;
use App\Services\Model3d\Contracts\Model3dApiClient;
use App\Services\Model3d\Model3dCatalogueService;
use Illuminate\Console\Command;

/**
 * Daily licence re-check for the MODEL_3D catalogue (spec 6.5 + principle 3:
 * source data is never authoritative). A creator can re-licence or delete a
 * model upstream after we ingested it; this re-fetches each published/gated
 * item and re-runs the ingest gate, so a licence that turned NC/ND flips the
 * item to CANNOT_PUBLISH and pulls it from public. A dead source keeps the
 * item producible (we hold our own file copy) but flags it for review.
 * Frozen quote snapshots are never touched.
 */
class ResyncModel3dCatalogue extends Command
{
    protected $signature = 'catalogue:resync-3d
        {--force : Re-download every model and re-record all its parts, healing pre-multi-part items whose stored file is a lone part}';

    protected $description = 'Re-check licences of MODEL_3D products against their source APIs.';

    public function handle(Model3dApiClient $client, Model3dCatalogueService $service): int
    {
        $count = 0;
        $pulled = 0;
        $force = (bool) $this->option('force');

        Product::query()
            ->where('class', ProductClass::Model3d->value)
            ->whereNotNull('model3d_id')
            ->with('model3d')
            ->chunkById(100, function ($products) use ($client, $service, $force, &$count, &$pulled): void {
                foreach ($products as $product) {
                    try {
                        $model = $product->model3d;
                        if ($model === null || $model->source === null) {
                            continue;
                        }

                        // OWNED models have no upstream to drift.
                        if ($model->source->value === 'OWNED') {
                            continue;
                        }

                        $data = $client->fetch($model->source, (string) $model->source_id);

                        if ($data === null) {
                            // Source dead/unreachable: we still hold the file, so
                            // production isn't blocked, but flag for review
                            // rather than silently keeping the item public.
                            //
                            // During a forced heal a null is very likely a
                            // transient rate-limit (the sweep hammers the API), so
                            // NEVER demote a published item then - only the normal
                            // daily resync treats a null as a dead source.
                            if (! $force && $product->publish_state === PublishState::Published) {
                                $product->publish_state = PublishState::CannotPublish;
                                $product->cannot_publish_reasons = ['needs_re-review', 'source_dead'];
                                $product->save();
                                $pulled++;
                            }

                            $count++;

                            continue;
                        }

                        $wasPublished = $product->publish_state === PublishState::Published;

                        ['product' => $product] = $service->ingest($data, $force);

                        // ingest() re-runs the licence/file gate; a licence that
                        // drifted off CC0/CC-BY lands in CANNOT_PUBLISH.
                        if ($wasPublished && $product->publish_state === PublishState::CannotPublish) {
                            $pulled++;
                        } elseif ($wasPublished && $product->publish_state === PublishState::ReadyToApprove) {
                            // Gate still clean - a re-ingest must not silently
                            // unpublish an already-approved item.
                            $product->publish_state = PublishState::Published;
                            $product->save();
                        }

                        $count++;
                    } catch (\Throwable $e) {
                        // Isolate per-item failure so one bad item never stalls
                        // the batch or the core flow.
                        report($e);
                    }
                }
            });

        $this->info("Re-checked {$count} MODEL_3D product(s); pulled {$pulled} from public.");

        return self::SUCCESS;
    }
}
