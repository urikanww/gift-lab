<?php

declare(strict_types=1);

namespace App\Services\Model3d;

use App\Enums\License;
use App\Enums\PrintMethod;
use App\Enums\ProductClass;
use App\Enums\PublishState;
use App\Models\Model3D;
use App\Models\PricingConfig;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
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
    ) {
    }

    /**
     * @param  bool  $forceFileRefresh  bypass the stored-file cache and
     *                                   re-download/re-record every part (heals
     *                                   pre-multi-part models whose stored file
     *                                   is a lone part).
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

        $stored = $license->isCommercialOk()
            ? $this->files->ensureAll($data, $force)
            : ['primary' => null, 'parts' => []];
        $localFile = $stored['primary'];

        return DB::transaction(function () use ($data, $license, $localFile, $stored, $force): array {
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

            // Physical footprint from the stored geometry (audit B10): source
            // APIs don't supply dimensions, but the STL we print from does.
            $this->fillDimensionsFromModel($product);

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
        if ($ref === null || ! Storage::disk('local')->exists($ref)) {
            return;
        }

        $dims = $this->dimensions->fromFile(Storage::disk('local')->path($ref));
        if ($dims !== null) {
            $product->dimensions = $dims;
        }
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
     * Publish gate. Reason tags: license_blocked, missing_credit,
     * missing_model_file, multi_file_review, estimates_unverified.
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
    ): array {
        if (! $license->isCommercialOk()) {
            return [PublishState::CannotPublish, ['license_blocked']];
        }

        if ($license->requiresCreatorCredit() && ($creatorCredit === null || $creatorCredit === '')) {
            return [PublishState::CannotPublish, ['missing_credit']];
        }

        if (! $hasFile) {
            // No locally stored printable file - we cannot produce this item.
            // Kept in the admin gate (not deleted) so staff can attach the
            // file manually for sources without a download API.
            return [PublishState::CannotPublish, ['missing_model_file']];
        }

        $autoPublish = (bool) PricingConfig::value('catalogue', 'auto_publish', false);
        $reasons = [];

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

        $canAutoPublish = $autoPublish && $estimatesVerified && ! $needsReview;
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
