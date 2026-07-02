<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Model3dSource;
use App\Enums\PublishState;
use App\Models\Product;
use App\Services\Model3d\Contracts\Model3dApiClient;
use App\Services\Model3d\Model3dCatalogueService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Pull real 3D models from Thingiverse (spec 6.5) into the MODEL_3D catalogue.
 * Searches the public API, fetches each hit through the bound Model3dApiClient
 * (live when THINGIVERSE_TOKEN is set), and ingests via Model3dCatalogueService
 * so the licence gate runs for real: only CC0 / CC-BY (with credit) items are
 * eligible; NC/unknown-licence hits are skipped, not stored.
 *
 *   php artisan catalogue:pull-3d "phone stand" --count=5 --publish
 */
class PullModel3dCatalogue extends Command
{
    protected $signature = 'catalogue:pull-3d
        {query : Search term, e.g. "keychain"}
        {--count=6 : Commercial-OK models to ingest before stopping}
        {--publish : Publish licence-cleared items immediately (skip the approval queue)}';

    protected $description = 'Search Thingiverse and ingest real, licence-cleared 3D models into the catalogue.';

    public function handle(Model3dApiClient $client, Model3dCatalogueService $service): int
    {
        $token = (string) config('services.thingiverse.token');
        if ($token === '') {
            $this->error('THINGIVERSE_TOKEN is not configured — live pull unavailable.');

            return self::FAILURE;
        }

        $query = (string) $this->argument('query');
        $target = max(1, (int) $this->option('count'));

        $response = Http::withToken($token)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(20)
            ->retry(2, 500, throw: false)
            ->get(config('services.thingiverse.base_url').'/search/'.rawurlencode($query), [
                'type' => 'things',
                'per_page' => 30,
                'sort' => 'popular',
            ]);

        if (! $response->successful()) {
            $this->error("Thingiverse search failed (HTTP {$response->status()}).");

            return self::FAILURE;
        }

        $hits = (array) $response->json('hits', []);
        if ($hits === []) {
            $this->warn("No results for \"{$query}\".");

            return self::SUCCESS;
        }

        $ingested = 0;
        $skipped = 0;

        foreach ($hits as $hit) {
            if ($ingested >= $target) {
                break;
            }

            $id = (string) ($hit['id'] ?? '');
            if ($id === '') {
                continue;
            }

            // Per-thing fetch (licence lives on the thing, not the search hit).
            $data = $client->fetch(Model3dSource::Thingiverse, $id);
            if ($data === null) {
                $skipped++;

                continue;
            }

            ['product' => $product] = $service->ingest($data);

            if ($product->publish_state === PublishState::CannotPublish) {
                // Licence not commercial-OK — remove the blocked rows again so a
                // discovery sweep doesn't fill the gate with unusable items.
                // Hard delete: model3ds has a unique(source, source_id) index and
                // ingest() ignores trashed rows, so a soft-deleted leftover would
                // blow up the next sweep that meets the same thing id.
                $product->forceDelete();
                $product->model3d?->forceDelete();
                $skipped++;
                $this->line("  skip  #{$id} {$data->name} [{$data->license}]");

                continue;
            }

            $this->mirrorImage($product);

            if ($this->option('publish') && $product->publish_state === PublishState::ReadyToApprove) {
                // Licence gate already passed in ingest(); --publish is the
                // operator's explicit approval, replacing the admin-gate click.
                $product->publish_state = PublishState::Published;
                $product->save();
            }

            $ingested++;
            $this->info("  ok    #{$id} {$data->name} [{$data->license}] → {$product->publish_state->value}");
        }

        $this->info("Ingested {$ingested} model(s), skipped {$skipped} (licence/fetch) for \"{$query}\".");

        return self::SUCCESS;
    }

    /**
     * Mirror the source thumbnail into our own public storage and point the
     * product at it. Thingiverse image URLs are resize-proxy links that can be
     * hotlink-blocked or die with the source; serving from our own disk keeps
     * the catalogue image stable. Skipped silently on download failure — the
     * remote URL stays as a best-effort fallback.
     */
    private function mirrorImage(Product $product): void
    {
        $remote = (string) $product->image_url;
        if ($remote === '' || ! str_starts_with($remote, 'http')) {
            return;
        }

        $path = "products/model3d-{$product->model3d_id}.jpg";

        if (! Storage::disk('public')->exists($path)) {
            try {
                $response = Http::connectTimeout(5)->timeout(20)->get($remote);
                if (! $response->successful()) {
                    return;
                }
                Storage::disk('public')->put($path, $response->body());
            } catch (\Throwable) {
                return;
            }
        }

        $product->image_url = url("storage/{$path}");
        $product->save();
    }
}
