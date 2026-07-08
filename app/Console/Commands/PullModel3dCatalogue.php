<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Model3dSource;
use App\Enums\PublishState;
use App\Models\LineItem;
use App\Models\Product;
use App\Services\Model3d\Contracts\Model3dApiClient;
use App\Services\Model3d\IpScreenService;
use App\Services\Model3d\Model3dCatalogueService;
use App\Services\Model3d\SlicerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Pull real 3D models from the configured sources (spec 6.5) into the
 * MODEL_3D catalogue. Each source is searched via its public API, every hit
 * is fetched through the bound Model3dApiClient (live when credentials are
 * set), and ingested via Model3dCatalogueService so the licence gate runs for
 * real: only CC0 / CC-BY items are eligible; NC/ND/SA/unknown-licence hits
 * are skipped, not stored. Cults3D is additionally restricted to free
 * listings - a paid file we have not purchased cannot be produced.
 *
 * Two intake modes:
 *   - keyword search (query argument): original behaviour, still available for
 *     ad-hoc/manual targeted pulls.
 *   - --browse=popular: keyword-less "popular feed" intake (Phase 2). Pages
 *     through each source's popular/trending feed instead of searching, so
 *     the nightly sweep no longer depends on a hand-maintained keyword list.
 *
 *   php artisan catalogue:pull-3d "phone stand" --count=5 --publish
 *   php artisan catalogue:pull-3d "vase" --source=cults3d --count=3
 *   php artisan catalogue:pull-3d --browse=popular --count=20
 */
class PullModel3dCatalogue extends Command
{
    /**
     * Hard cap on pages consulted per source in --browse mode, independent
     * of --count. Protects a nightly sweep from paging forever if a source's
     * feed never satisfies --count (e.g. all commercial-blocked).
     */
    private const MAX_BROWSE_PAGES = 10;

    protected $signature = 'catalogue:pull-3d
        {query? : Search term (omit in --browse mode), e.g. "keychain"}
        {--browse= : Browse a keyword-less feed instead of searching: "popular"}
        {--count=6 : Commercial-OK models to ingest per source before stopping}
        {--source=all : Source to pull from: all, thingiverse, cults3d}
        {--publish : Publish licence-cleared items immediately (skip the approval queue)}';

    protected $description = 'Search (or browse) the 3D model sources and ingest real, licence-cleared models into the catalogue.';

    public function handle(Model3dApiClient $client, Model3dCatalogueService $service): int
    {
        $query = (string) $this->argument('query');
        $target = max(1, (int) $this->option('count'));
        $sourceOpt = strtolower((string) $this->option('source'));
        $browseOpt = $this->option('browse');

        $sources = match ($sourceOpt) {
            'all' => [Model3dSource::Thingiverse, Model3dSource::Cults3d],
            'thingiverse' => [Model3dSource::Thingiverse],
            'cults3d' => [Model3dSource::Cults3d],
            default => null,
        };

        if ($sources === null) {
            $this->error("Unknown --source \"{$sourceOpt}\" (use all, thingiverse, or cults3d).");

            return self::FAILURE;
        }

        $browse = false;

        if ($browseOpt !== null) {
            if (strtolower((string) $browseOpt) !== 'popular') {
                $this->error("Unsupported --browse \"{$browseOpt}\" (only \"popular\" is supported).");

                return self::FAILURE;
            }

            $browse = true;
        } elseif ($query === '') {
            $this->error('Provide a search term or use --browse=popular.');

            return self::FAILURE;
        }

        $failed = false;

        foreach ($sources as $source) {
            if ($browse) {
                $failed = $this->pullBrowse($source, $target, $client, $service) ? $failed : true;

                continue;
            }

            $ids = $this->search($source, $query);

            if ($ids === null) {
                // Missing credentials or upstream failure - already reported.
                $failed = true;

                continue;
            }

            $this->pull($source, $ids, $query, $target, $client, $service);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Keyword-less popular-feed intake for one source: pages the source's
     * browse method up to MAX_BROWSE_PAGES, feeding each page's ids into the
     * existing pull() so the licence/IP/file gate stays identical to search
     * mode. Stops early once pull() reports it ingested >= $target so a
     * nightly sweep doesn't page further than it needs to.
     *
     * Returns false on a hard per-source failure (missing credentials /
     * upstream error on the first page), true otherwise - mirrors search()'s
     * null-signals-failure contract without reusing its return type, since
     * browse already knows how many items it ingested.
     */
    private function pullBrowse(
        Model3dSource $source,
        int $target,
        Model3dApiClient $client,
        Model3dCatalogueService $service,
    ): bool {
        $totalIngested = 0;
        $sawAnyPage = false;

        for ($page = 1; $page <= self::MAX_BROWSE_PAGES; $page++) {
            $ids = match ($source) {
                Model3dSource::Thingiverse => $this->browseThingiverse($page),
                Model3dSource::Cults3d => $this->browseCults3d($page),
                default => null,
            };

            if ($ids === null) {
                // Missing credentials or upstream failure - already reported.
                // A failure on page 1 is a hard failure for this source; a
                // failure on a later page just ends the sweep for tonight.
                return $sawAnyPage;
            }

            $sawAnyPage = true;

            if ($ids === []) {
                // Feed exhausted before MAX_BROWSE_PAGES - nothing more to page.
                break;
            }

            $totalIngested += $this->pull($source, $ids, 'popular', $target - $totalIngested, $client, $service);

            if ($totalIngested >= $target) {
                // Target met - no need to page further tonight.
                break;
            }
        }

        return true;
    }

    /**
     * @param  array<int, string>  $ids
     * @return int number of commercial-OK items ingested this call (used by
     *             pullBrowse() to decide whether another page is needed)
     */
    private function pull(
        Model3dSource $source,
        array $ids,
        string $query,
        int $target,
        Model3dApiClient $client,
        Model3dCatalogueService $service,
    ): int {
        if ($ids === []) {
            $this->warn("[{$source->value}] no results for \"{$query}\".");

            return 0;
        }

        if ($target <= 0) {
            return 0;
        }

        $ingested = 0;
        $skipped = 0;

        foreach ($ids as $id) {
            if ($ingested >= $target) {
                break;
            }

            // Per-item fetch (the licence lives on the item, not the search hit).
            $data = $client->fetch($source, $id);
            if ($data === null) {
                $skipped++;

                continue;
            }

            // IP/trademark screen - a CC licence doesn't clear trademarks.
            // Flagged items still flow IN (owner decision: full visibility),
            // but are held in the admin gate as CANNOT_PUBLISH with the flag
            // reason shown; staff decide, nothing flagged auto-publishes.
            $verdict = app(IpScreenService::class)->screen($data->name, $data->description);

            ['product' => $product] = $service->ingest($data);

            if ($verdict['flagged']) {
                $product->publish_state = PublishState::CannotPublish;
                $product->cannot_publish_reasons = array_values(array_unique(array_merge(
                    (array) ($product->cannot_publish_reasons ?? []),
                    ['ip_flag:'.$verdict['reason']],
                )));
                $product->save();
                $this->line("  hold  [{$source->value}] {$id} {$data->name} [IP: {$verdict['reason']}] → gate");
            }

            $reasons = (array) ($product->cannot_publish_reasons ?? []);
            $licenceBlocked = array_intersect($reasons, ['license_blocked', 'missing_credit']) !== [];

            if ($product->publish_state === PublishState::CannotPublish && $licenceBlocked) {
                // Licence not commercial-OK - remove the blocked rows again so a
                // discovery sweep doesn't fill the gate with unusable items.
                // Hard delete: model3ds has a unique(source, source_id) index and
                // ingest() ignores trashed rows, so a soft-deleted leftover would
                // blow up the next sweep that meets the same source id.
                // Items blocked only on missing_model_file are KEPT - staff can
                // attach the file manually (e.g. Cults3D has no download API).
                // A product referenced by order lines can't be hard-deleted (FK)
                // - that history must survive, so it stays as a CANNOT_PUBLISH
                // row in the gate instead.
                if (LineItem::query()->where('product_id', $product->id)->exists()) {
                    $skipped++;
                    $this->line("  hold  [{$source->value}] {$id} {$data->name} [{$data->license}] → gate (has order history)");

                    continue;
                }

                $product->forceDelete();
                $product->model3d?->forceDelete();
                $skipped++;
                $this->line("  skip  [{$source->value}] {$id} {$data->name} [{$data->license}]");

                continue;
            }

            $this->mirrorImage($product);

            // Measured grams/print-minutes when a slicer is configured -
            // auto-verifies estimates so the item can publish untouched.
            app(SlicerService::class)->measure($product);
            $product->refresh();

            // Re-run the gate now that the slicer may have verified estimates:
            // ingest parked the item at READY_TO_APPROVE with placeholder
            // estimates, so without this an item the inline slicer just verified
            // would sit in the queue until a later sweep re-ingested it (and
            // slice-pending skips it, being already verified). Honours the
            // auto-publish toggle and IP holds - mirrors catalogue:slice-pending.
            $product = $service->autoPublishIfCleared($product);

            if ($this->option('publish') && $product->publish_state === PublishState::ReadyToApprove) {
                // --publish is the operator's explicit approval, replacing the
                // admin-gate click; publish() re-runs the full gate.
                $product = $service->publish($product);
            }

            $ingested++;
            $this->info("  ok    [{$source->value}] {$id} {$data->name} [{$data->license}] → {$product->publish_state->value}");
        }

        $this->info("[{$source->value}] ingested {$ingested}, skipped {$skipped} (licence/fetch) for \"{$query}\".");

        return $ingested;
    }

    /**
     * Search one source and return its item ids (Thingiverse thing ids,
     * Cults3D creation slugs). Null signals a hard failure (missing
     * credentials / upstream error); an empty array is a valid no-hit result.
     *
     * @return array<int, string>|null
     */
    private function search(Model3dSource $source, string $query): ?array
    {
        return match ($source) {
            Model3dSource::Thingiverse => $this->searchThingiverse($query),
            Model3dSource::Cults3d => $this->searchCults3d($query),
            default => null,
        };
    }

    /**
     * @return array<int, string>|null
     */
    private function searchThingiverse(string $query): ?array
    {
        $token = (string) config('services.thingiverse.token');
        if ($token === '') {
            $this->error('[THINGIVERSE] THINGIVERSE_TOKEN is not configured - skipping.');

            return null;
        }

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
            $this->error("[THINGIVERSE] search failed (HTTP {$response->status()}).");

            return null;
        }

        return collect((array) $response->json('hits', []))
            ->pluck('id')
            ->filter()
            ->map(fn ($id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>|null
     */
    private function searchCults3d(string $query): ?array
    {
        $username = (string) config('services.cults3d.username');
        $token = (string) config('services.cults3d.token');
        if ($username === '' || $token === '') {
            $this->error('[CULTS3D] CULTS3D_USERNAME / CULTS3D_TOKEN are not configured - skipping.');

            return null;
        }

        $gql = <<<'GQL'
        query ($query: String!, $limit: Int) {
          creationsSearchBatch(query: $query, onlyFree: true, onlyCommercial: true, limit: $limit) {
            results { slug }
          }
        }
        GQL;

        $response = Http::withBasicAuth($username, $token)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(25)
            ->retry(2, 500, throw: false)
            ->post((string) config('services.cults3d.base_url'), [
                'query' => $gql,
                'variables' => ['query' => $query, 'limit' => 30],
            ]);

        if (! $response->successful() || $response->json('errors') !== null) {
            $this->error("[CULTS3D] search failed (HTTP {$response->status()}).");

            return null;
        }

        return collect((array) $response->json('data.creationsSearchBatch.results', []))
            ->pluck('slug')
            ->filter()
            ->map(fn ($slug): string => (string) $slug)
            ->values()
            ->all();
    }

    /**
     * Page through Thingiverse's "popular" feed - no search query, sorted by
     * the source's own popularity ranking. Unlike /search/ (which wraps hits in
     * a "hits" key), /popular returns a bare top-level array of things, so we
     * pluck ids straight off the response root.
     *
     * @return array<int, string>|null
     */
    private function browseThingiverse(int $page): ?array
    {
        $token = (string) config('services.thingiverse.token');
        if ($token === '') {
            $this->error('[THINGIVERSE] THINGIVERSE_TOKEN is not configured - skipping.');

            return null;
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(20)
            ->retry(2, 500, throw: false)
            ->get(config('services.thingiverse.base_url').'/popular', [
                'page' => $page,
                'per_page' => 30,
            ]);

        if (! $response->successful()) {
            $this->error("[THINGIVERSE] browse failed (HTTP {$response->status()}).");

            return null;
        }

        return collect((array) $response->json())
            ->pluck('id')
            ->filter()
            ->map(fn ($id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * Page through a keyword-less "most downloaded" Cults3D feed.
     *
     * creationsBatch returns a CreationBatch wrapper (results { slug }), same
     * shape as creationsSearchBatch - verified against the live Cults3D GraphQL
     * API. Introspection is disabled on their prod endpoint, so the field/enum
     * names here (creationsBatch, sort: BY_DOWNLOADS, results) were confirmed by
     * exercising the live query directly, not by schema introspection.
     *
     * @return array<int, string>|null
     */
    private function browseCults3d(int $page): ?array
    {
        $username = (string) config('services.cults3d.username');
        $token = (string) config('services.cults3d.token');
        if ($username === '' || $token === '') {
            $this->error('[CULTS3D] CULTS3D_USERNAME / CULTS3D_TOKEN are not configured - skipping.');

            return null;
        }

        $limit = 30;
        $offset = ($page - 1) * $limit;

        $gql = <<<'GQL'
        query ($limit: Int, $offset: Int) {
          creationsBatch(sort: BY_DOWNLOADS, onlyFree: true, onlyCommercial: true, limit: $limit, offset: $offset) {
            results { slug }
          }
        }
        GQL;

        $response = Http::withBasicAuth($username, $token)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(25)
            ->retry(2, 500, throw: false)
            ->post((string) config('services.cults3d.base_url'), [
                'query' => $gql,
                'variables' => ['limit' => $limit, 'offset' => $offset],
            ]);

        if (! $response->successful() || $response->json('errors') !== null) {
            $this->error("[CULTS3D] browse failed (HTTP {$response->status()}).");

            return null;
        }

        return collect((array) $response->json('data.creationsBatch.results', []))
            ->pluck('slug')
            ->filter()
            ->map(fn ($slug): string => (string) $slug)
            ->values()
            ->all();
    }

    /**
     * Mirror the source thumbnail into our own public storage and point the
     * product at it. Source image URLs are proxy/CDN links that can be
     * hotlink-blocked or die with the source; serving from our own disk keeps
     * the catalogue image stable. Skipped silently on download failure - the
     * remote URL stays as a best-effort fallback. (assets:migrate-to-spaces
     * later moves these into the GIFT_LAB folder on Spaces.)
     */
    private function mirrorImage(Product $product): void
    {
        $remote = (string) $product->image_url;
        if ($remote === '' || ! str_starts_with($remote, 'http')) {
            return;
        }

        // Already self-hosted (local storage or our Spaces folder).
        if (str_contains($remote, (string) config('app.url')) || str_contains($remote, '/GIFT_LAB/')) {
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
