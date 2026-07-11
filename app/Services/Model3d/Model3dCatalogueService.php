<?php

declare(strict_types=1);

namespace App\Services\Model3d;

use App\Enums\License;
use App\Enums\Model3dSource;
use App\Enums\PrintMethod;
use App\Enums\ProductClass;
use App\Enums\PublishState;
use App\Models\Model3D;
use App\Models\PricingConfig;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * 3D model catalogue lifecycle (spec 6.5). Ingest a model, gate publication on
 * its licence (only CC0 / CC_BY / OWNED are commercial-OK; CC_BY must carry
 * creator credit; anything else is blocked), require a locally stored printable
 * file (the floor prints from our copy, never a source link), and mirror the
 * decision onto a MODEL_3D catalogue Product that carries the filament spec
 * for procurement. Auto-publish additionally requires staff-verified filament
 * estimates - source APIs don't provide real grams/material, so ingest
 * defaults are placeholders until someone confirms them.
 */
final class Model3dCatalogueService
{
    public function __construct(
        private readonly Model3dFileStore $files,
        private readonly StlDimensions $dimensions,
        private readonly IpScreenService $ipScreen,
        private readonly AssetStore $assets,
    ) {}

    /**
     * @param  bool  $forceFileRefresh  bypass the stored-file cache and
     *                                  re-download/re-record every part (heals
     *                                  pre-multi-part models whose stored file
     *                                  is a lone part).
     * @return array{model: Model3D, product: Product}
     */
    public function ingest(Model3dData $data, bool $forceFileRefresh = false): array
    {
        // Download outside the DB transaction - an HTTP fetch must never hold
        // a write transaction open. Only worth attempting for commercial-OK
        // licences; blocked items are never produced.
        $license = License::tryFrom($data->license) ?? License::Blocked;

        // Version-aware refresh: when the source's last-modified marker changed
        // since we stored it, re-download (and re-record parts) even on a normal
        // resync - so an updated upstream model refreshes without a global
        // --force sweep. Unchanged models stay cache-hit (cheap).
        $storedVersion = Model3D::where('source', $data->source->value)
            ->where('source_id', $data->sourceId)
            ->value('source_version');
        $versionChanged = $data->sourceVersion !== null
            && $storedVersion !== null
            && $storedVersion !== $data->sourceVersion;
        $force = $forceFileRefresh || $versionChanged;

        // Owner decision: any licence with a valid model is brought in for staff
        // review, so we attempt the download regardless of licence (a blocked
        // licence no longer short-circuits intake). The gate still refuses to
        // AUTO-publish a non-commercial/uncredited licence - staff approve those.
        $stored = $this->files->ensureAll($data, $force);
        $localFile = $stored['primary'];

        // Thingiverse HAS a download API: if a listing exposes no printable file
        // it genuinely ships no 3D model (images/renders only) → skip it until a
        // model can be pulled. Cults3D has no download API, so a missing file
        // there means "staff attach manually", not "no model exists".
        $skipUntilModel = $data->source === Model3dSource::Thingiverse
            && $data->downloadUrl === null
            && $data->downloadFiles === [];

        $result = DB::transaction(function () use ($data, $license, $localFile, $stored, $force, $skipUntilModel): array {
            $model = Model3D::where('source', $data->source->value)
                ->where('source_id', $data->sourceId)
                ->first()
                ?? new Model3D(['source' => $data->source->value, 'source_id' => $data->sourceId]);

            $model->license = $license;
            $model->creator_credit = $data->creatorCredit;
            $model->file_ref = $data->fileRef;
            // Record the source version we just fetched, so the next resync can
            // tell whether the upstream model changed.
            if ($data->sourceVersion !== null) {
                $model->source_version = $data->sourceVersion;
            }

            $product = Product::where('model3d_id', $model->id)->first()
                ?? new Product(['class' => ProductClass::Model3d->value]);

            // A previously stored file survives a re-ingest where the source
            // stopped exposing downloads; staff-verified estimates survive too.
            $productionFile = $localFile ?? $this->existingLocalFile($product);

            [$publishState, $reasons] = $this->gate(
                $license,
                $data->creatorCredit,
                $productionFile !== null,
                (bool) $product->estimates_verified,
                $this->hasMultiplePrintableFiles($data),
                // Parts we're about to persist, or ones already recorded from a
                // prior ingest (a cache-hit resync returns no fresh parts).
                $stored['parts'] !== [] || $this->productHasParts($product),
                $skipUntilModel,
            );

            $model->publish_state = $publishState;
            $model->cannot_publish_reasons = $reasons;
            $model->save();

            $product->class = ProductClass::Model3d;
            $product->model3d_id = $model->id;
            $product->name = $data->name;
            $product->image_url = $data->imageUrl;
            $product->description = $data->description;
            $product->base_cost = 0; // cost is filament + print, priced dynamically
            $product->print_method = PrintMethod::Fdm;
            $product->stock_mode = 'MAKE_TO_ORDER';
            $product->is_printable = $productionFile !== null;
            $product->license = $license;
            $product->creator_credit = $data->creatorCredit;
            $product->model_file_ref = $productionFile ?? $data->fileRef;

            // Never clobber staff-verified filament facts with API placeholders.
            if (! $product->estimates_verified) {
                $product->filament_material = $data->filamentMaterial;
                $product->filament_color = $data->filamentColor;
                $product->est_grams = $data->estGrams;
            }

            $product->publish_state = $publishState;
            $product->cannot_publish_reasons = $reasons;

            // A forced heal replaces the primary geometry (a lone part → the
            // richest part, with the rest recorded), so stale auto-derived
            // dimensions must be recomputed too - unless staff have verified
            // this product's estimates.
            if ($force && ! $product->estimates_verified) {
                $product->dimensions = null;
            }

            // MakerWorld: the stored file is a print-ready .3mf. Derive an STL for
            // the viewer/dimensions/estimate-slice (three.js renders STL, not 3MF)
            // and keep the original .3mf as the print-floor production file.
            $this->deriveStlFromThreeMf($product, strtolower($data->source->value), $data->sourceId);

            // Physical footprint from the stored geometry (audit B10): source
            // APIs don't supply dimensions, but the STL we print from does.
            $this->fillDimensionsFromModel($product);

            // IP/trademark screen runs HERE (not in the caller) so every ingest
            // path - Thingiverse pull AND the CSV/MakerWorld import - carries the
            // flag identically. Policy change: a flagged-but-otherwise-valid item
            // is NON-BLOCKING - it's surfaced as a tag (badge + human approval),
            // NOT forced to CANNOT_PUBLISH. The publish gate above ignores it.
            $verdict = $this->ipScreen->screen($data->name, $data->description);
            $product->ip_flagged = $verdict['flagged'];
            $product->ip_flag_reason = $verdict['flagged'] ? $verdict['reason'] : null;

            $product->save();

            // Persist the individual parts of a multi-part figure. A fresh
            // multi-file download replaces the recorded set; a forced single-file
            // download clears any stale parts; a cache-hit resync (empty parts,
            // not forced) leaves the existing rows untouched.
            if ($stored['parts'] !== []) {
                $product->modelParts()->delete();
                $product->modelParts()->createMany($stored['parts']);
            } elseif ($force && $stored['primary'] !== null) {
                $product->modelParts()->delete();
            }

            return ['model' => $model, 'product' => $product];
        });

        // Thumbnail mirror runs AFTER the transaction (it makes an HTTP fetch; a
        // write lock must never span network I/O). Both ingest paths share it.
        $this->mirrorThumbnail($result['product']);

        return $result;
    }

    /**
     * Populate missing dimensions from the stored model file's bounding box.
     * Never overwrites explicitly set dimensions.
     */
    public function fillDimensionsFromModel(Product $product): void
    {
        if ($product->dimensions !== null) {
            return;
        }

        $ref = $this->existingLocalFile($product);
        $disk = (string) config('model3d.disk', 'local');
        if ($ref === null || ! Storage::disk($disk)->exists($ref)) {
            return;
        }

        // Bounding-box read needs a real local path; on S3 that's a temp copy.
        [$path, $cleanup] = ModelFileAccess::localPath($disk, $ref);
        try {
            $dims = $this->dimensions->fromFile($path);
        } finally {
            $cleanup();
        }
        if ($dims !== null) {
            $product->dimensions = $dims;
        }
    }

    /**
     * When the stored model file is a .3mf (MakerWorld), derive a viewer/slice
     * STL from it and keep the .3mf as the production file. three.js renders STL
     * only, and dimensions/estimate-slice read STL - so a raw .3mf as
     * model_file_ref would show nothing and measure nothing. The original .3mf is
     * exactly what the H2S floor prints, so it becomes production_file_ref.
     *
     * Best-effort: a conversion failure (typed exception) leaves the .3mf in
     * place as model_file_ref (still downloadable/printable) with no derived STL,
     * and does NOT fail the ingest. Inert until the ThreeMfToStl service exists.
     */
    private function deriveStlFromThreeMf(Product $product, string $source, string $sourceId): void
    {
        $ref = (string) ($product->model_file_ref ?? '');
        if ($ref === '' || str_starts_with($ref, 'http') || ! str_ends_with(strtolower($ref), '.3mf')) {
            return;
        }
        if (! class_exists(ThreeMfToStl::class)) {
            return;
        }

        $disk = (string) config('model3d.disk', 'local');
        if (! Storage::disk($disk)->exists($ref)) {
            return;
        }

        try {
            $stl = app(ThreeMfToStl::class)->convert((string) Storage::disk($disk)->get($ref));
        } catch (\Throwable $e) {
            Log::warning('3mf->STL derivation failed; keeping .3mf as model file.', [
                'ref' => $ref,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $stlRef = $this->assets->storeModelFile($source, $sourceId, $stl, 'stl');

        // The .3mf is the print-floor file; the derived STL drives the app.
        $product->production_file_ref = $ref;
        $product->model_file_ref = $stlRef;
    }

    /**
     * Mirror the source thumbnail onto our own storage (via the shared AssetStore,
     * so the Thingiverse pull and the CSV/MakerWorld import mirror identically)
     * and point the product at the stable URL. Silent-skip on failure keeps the
     * source URL as a fallback. Runs outside the ingest transaction - a thumbnail
     * fetch must never hold a write lock.
     */
    public function mirrorThumbnail(Product $product): void
    {
        $remote = (string) ($product->image_url ?? '');
        $source = $product->model3d !== null ? strtolower($product->model3d->source->value) : 'model3d';
        $sourceId = $product->model3d?->source_id ?? (string) $product->id;

        $url = $this->assets->storeThumbnail($source, (string) $sourceId, $remote);
        if ($url !== null && $url !== $remote) {
            $product->image_url = $url;
            $product->save();
        }
    }

    /**
     * Converge the CSV/MakerWorld import onto the same enrichment the API pull
     * gets, WITHOUT overwriting the CSV's own pricing/dimensions (full ingest is
     * tuned for dynamically-priced API scrapes and would zero base_cost etc).
     * This closes the "CSV bypass" gap: an imported MODEL_3D product now gets a
     * linked Model3D provenance row, the non-blocking IP flag, a derived STL +
     * production .3mf, a mirrored thumbnail, and geometry-derived dimensions -
     * exactly the pieces the direct-write importer skipped.
     *
     * Idempotent on (source, source_id): re-importing updates the same Model3D.
     * Publish state is the CALLER's concern (the importer forces PENDING); this
     * never publishes.
     */
    public function enrichImportedProduct(Product $product, Model3dSource $source): void
    {
        $sourceId = (string) ($product->source_product_id ?? '');
        if ($sourceId === '') {
            // No stable source id -> can't dedup a provenance row; still run the
            // IP screen so the flag is present, then stop.
            $this->applyIpFlag($product);
            $product->save();

            return;
        }

        // Link (or reuse) the Model3D provenance row, keyed on (source, id).
        $model = Model3D::where('source', $source->value)
            ->where('source_id', $sourceId)
            ->first()
            ?? new Model3D(['source' => $source->value, 'source_id' => $sourceId]);
        $model->license = $product->license ?? License::Blocked;
        $model->creator_credit = $product->creator_credit;
        $model->file_ref = $product->model_file_ref;
        $model->publish_state = $product->publish_state;
        $model->save();

        $product->model3d_id = $model->id;

        // Non-blocking IP tag + MakerWorld .3mf -> STL derivation (+ production file).
        $this->applyIpFlag($product);
        $this->deriveStlFromThreeMf($product, strtolower($source->value), $sourceId);

        // Only auto-fill dimensions the CSV left blank/zero.
        if ($this->dimensionsAreBlank($product)) {
            $product->dimensions = null;
            $this->fillDimensionsFromModel($product);
        }

        $product->save();

        // Thumbnail mirror last (HTTP fetch; own save on success).
        $this->mirrorThumbnail($product);
    }

    private function applyIpFlag(Product $product): void
    {
        $verdict = $this->ipScreen->screen((string) $product->name, $product->description);
        $product->ip_flagged = $verdict['flagged'];
        $product->ip_flag_reason = $verdict['flagged'] ? $verdict['reason'] : null;
    }

    /** True when the CSV supplied only placeholder (zero/absent) dimensions. */
    private function dimensionsAreBlank(Product $product): bool
    {
        $d = (array) ($product->dimensions ?? []);

        return ((float) ($d['l'] ?? 0)) <= 0
            && ((float) ($d['w'] ?? 0)) <= 0
            && ((float) ($d['h'] ?? 0)) <= 0;
    }

    /**
     * Staff approval for a MODEL_3D item (class-aware counterpart of the
     * scraped publish): re-runs the gate so a stale READY_TO_APPROVE flag
     * can't push an unproducible item public.
     */
    public function publish(Product $product): Product
    {
        [$state, $reasons] = $this->gate(
            $product->license ?? License::Blocked,
            $product->creator_credit,
            $this->existingLocalFile($product) !== null,
            (bool) $product->estimates_verified,
        );

        if ($state === PublishState::CannotPublish) {
            $product->publish_state = $state;
            $product->cannot_publish_reasons = $reasons;
        } elseif (! $product->estimates_verified) {
            // Placeholder filament estimates must be confirmed before ANY
            // publication - approving unverified numbers would quote and
            // procure against fiction. Held in the gate with the reason shown.
            $product->publish_state = PublishState::ReadyToApprove;
            $product->cannot_publish_reasons = ['estimates_unverified'];
        } else {
            // Gate passed; the staff click is the approval itself.
            $product->publish_state = PublishState::Published;
            $product->cannot_publish_reasons = null;
        }

        $product->save();
        $this->syncModelState($product);

        return $product;
    }

    /**
     * Post-slicer auto-publish: once measurements verify the estimates, run
     * the gate again so a fully cleared item publishes without a staff click
     * (when the auto-publish toggle is on). Items held for IP review keep
     * their hold - only an explicit staff publish overrides an ip_flag.
     */
    public function autoPublishIfCleared(Product $product): Product
    {
        $ipHeld = collect((array) ($product->cannot_publish_reasons ?? []))
            ->contains(fn ($reason): bool => str_starts_with((string) $reason, 'ip_flag'));

        if ($ipHeld) {
            return $product;
        }

        [$state, $reasons] = $this->gate(
            $product->license ?? License::Blocked,
            $product->creator_credit,
            $this->existingLocalFile($product) !== null,
            (bool) $product->estimates_verified,
            $this->wasMultiFileFlagged($product),
            $this->productHasParts($product),
        );

        $product->publish_state = $state;
        $product->cannot_publish_reasons = $reasons;
        $product->save();
        $this->syncModelState($product);

        return $product;
    }

    /**
     * Re-evaluate the publish gate against the product's current facts
     * (after staff attach a file, fix credit, etc.) without publishing.
     */
    public function regate(Product $product): Product
    {
        [$state, $reasons] = $this->gate(
            $product->license ?? License::Blocked,
            $product->creator_credit,
            $this->existingLocalFile($product) !== null,
            (bool) $product->estimates_verified,
            $this->wasMultiFileFlagged($product),
            $this->productHasParts($product),
        );

        // Never jump straight to Published from a re-gate; publication is an
        // explicit staff or auto-publish decision.
        $product->publish_state = $state === PublishState::Published ? PublishState::ReadyToApprove : $state;
        $product->cannot_publish_reasons = $reasons;
        // A newly attached file may supply the missing footprint (audit B10).
        $this->fillDimensionsFromModel($product);
        $product->save();
        $this->syncModelState($product);

        return $product;
    }

    public function unpublish(Product $product): Product
    {
        $product->publish_state = PublishState::ReadyToApprove;
        $product->save();
        $this->syncModelState($product);

        return $product;
    }

    /**
     * Staff confirm the production estimates (filament material/colour and
     * per-unit grams) that the source API could not provide. Re-evaluates the
     * publish gate so a fully cleared item can now auto-publish.
     */
    public function verifyEstimates(Product $product, string $material, string $color, float $grams): Product
    {
        $product->filament_material = $material;
        $product->filament_color = $color;
        $product->est_grams = $grams;
        $product->estimates_verified = true;

        [$state, $reasons] = $this->gate(
            $product->license ?? License::Blocked,
            $product->creator_credit,
            $this->existingLocalFile($product) !== null,
            true,
            $this->wasMultiFileFlagged($product),
            $this->productHasParts($product),
        );

        $product->publish_state = $state;
        $product->cannot_publish_reasons = $reasons;
        $product->save();
        $this->syncModelState($product);

        return $product;
    }

    /**
     * Whether the product has recorded individual model parts. When it does, a
     * multi-file source is fully captured (every part persisted), so the
     * multi_file_review hold no longer applies.
     */
    private function productHasParts(Product $product): bool
    {
        return $product->exists && $product->modelParts()->exists();
    }

    /**
     * Publish gate. Reason tags: awaiting_model_file, missing_model_file,
     * license_review, multi_file_review, estimates_unverified.
     *
     * Licence no longer HARD-blocks intake (owner decision: any licence with a
     * valid model is brought in for staff review). The only hard block left is
     * the absence of a locally producible file. A non-commercial / uncredited
     * licence is surfaced as `license_review` and held out of AUTO-publish -
     * staff must approve it consciously.
     *
     * @return array{0: PublishState, 1: array<int, string>|null}
     */
    private function gate(
        License $license,
        ?string $creatorCredit,
        bool $hasFile,
        bool $estimatesVerified,
        bool $multiFile = false,
        bool $partsRecorded = false,
        bool $skipUntilModel = false,
    ): array {
        if (! $hasFile) {
            // No locally stored printable file - we cannot produce this item.
            // Kept in the admin gate (not deleted), two shades:
            //  - the source (Thingiverse) shipped no printable model at all →
            //    skip until one can be pulled (a listing that is images/renders only);
            //  - the source lists files we couldn't store locally (e.g. Cults3D
            //    has no download API) → staff attach the file manually.
            $reason = $skipUntilModel ? 'awaiting_model_file' : 'missing_model_file';

            return [PublishState::CannotPublish, [$reason]];
        }

        $autoPublish = (bool) PricingConfig::value('catalogue', 'auto_publish', false);
        $reasons = [];

        // A licence that isn't cleanly commercial-OK - blocked/unknown, or an
        // attribution licence with no creator credit - is brought IN but flagged
        // for a staff decision and never allowed to auto-publish.
        $licenceNeedsReview = ! $license->isCommercialOk()
            || ($license->requiresCreatorCredit() && ($creatorCredit === null || $creatorCredit === ''));
        if ($licenceNeedsReview) {
            $reasons[] = 'license_review';
        }

        // A source that ships several printable files needed a human to confirm
        // the stored geometry back when ingest kept only the largest file and
        // dropped the rest. Now ensureAll() persists EVERY part, so once parts
        // are recorded the full set is captured and no review is needed. Only
        // hold when the files couldn't be persisted (e.g. an alternate 3MF/OBJ
        // dropped alongside a single stored STL).
        $needsReview = $multiFile && ! $partsRecorded;
        if ($needsReview) {
            $reasons[] = 'multi_file_review';
        }

        // Placeholder estimates must pass through a human (or slicer) before
        // the item can skip the approval queue.
        if ($autoPublish && ! $estimatesVerified) {
            $reasons[] = 'estimates_unverified';
        }

        $canAutoPublish = $autoPublish && $estimatesVerified && ! $needsReview && ! $licenceNeedsReview;
        $state = $canAutoPublish ? PublishState::Published : PublishState::ReadyToApprove;

        return [$state, $reasons === [] ? null : $reasons];
    }

    /**
     * More than one printable file (or a zip bundle) means the source ships
     * parts/variants/alternate layouts - staff should confirm the stored file.
     */
    private function hasMultiplePrintableFiles(Model3dData $data): bool
    {
        $printable = array_filter(
            $data->downloadFiles,
            static fn (array $f): bool => preg_match('/\.(stl|3mf|obj|zip)$/i', (string) ($f['name'] ?? '')) === 1,
        );

        if (count($printable) > 1) {
            return true;
        }

        // A single zip may itself bundle several parts.
        foreach ($printable as $f) {
            if (str_ends_with(strtolower((string) ($f['name'] ?? '')), '.zip')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The multi-file review flag is set at ingest from the source file list, but
     * the source data isn't available on later re-gates (autoPublishIfCleared,
     * regate, verifyEstimates), so it persists via the existing reason until a
     * staff action clears it.
     */
    private function wasMultiFileFlagged(Product $product): bool
    {
        return in_array('multi_file_review', (array) ($product->cannot_publish_reasons ?? []), true);
    }

    /**
     * The product's producible file, i.e. a non-URL model_file_ref pointing
     * at our own storage (or a bundled fixture path).
     */
    private function existingLocalFile(Product $product): ?string
    {
        $ref = (string) ($product->model_file_ref ?? '');

        return $ref !== '' && ! str_starts_with($ref, 'http') ? $ref : null;
    }

    /**
     * Keep the Model3D row's publish state mirroring its product after admin
     * actions, so gate listings stay consistent.
     */
    private function syncModelState(Product $product): void
    {
        $model = $product->model3d;
        if ($model === null) {
            return;
        }

        $model->publish_state = $product->publish_state;
        $model->cannot_publish_reasons = $product->cannot_publish_reasons;
        $model->save();
    }
}
