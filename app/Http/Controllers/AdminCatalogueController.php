<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ProductClass;
use App\Enums\PublishState;
use App\Models\PricingConfig;
use App\Models\Product;
use App\Models\ProductModelPart;
use App\Services\AuditLogger;
use App\Services\Catalogue\ScrapedCatalogueService;
use App\Services\Model3d\Contracts\Model3dApiClient;
use App\Services\Model3d\Model3dCatalogueService;
use App\Services\Model3d\ModelFileAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

/**
 * Superadmin/staff catalogue gate (spec 6.7): review scraped + 3D items, approve
 * for publication, pull from public, and toggle global auto-publish. Staff-only;
 * the auto-publish setting is superadmin-only. Publish/unpublish route through
 * the class's own service - the scraped CompletenessGate checks price/dims/stock
 * that MODEL_3D items structurally never have, so 3D items get the 3D gate
 * (licence + credit + local file + verified estimates).
 */
class AdminCatalogueController extends Controller
{
    public function __construct(
        private readonly ScrapedCatalogueService $scraped,
        private readonly Model3dCatalogueService $model3d,
        private readonly Model3dApiClient $apiClient,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);

        // Bounded pagination (matches the public CatalogueController) - the admin
        // gate previously did an unbounded ->get() over all SCRAPED_UV + MODEL_3D
        // rows, so response size/memory grew linearly with scraped inventory.
        $perPage = max(1, min((int) $request->integer('per_page', 24), 100));

        // Name/creator search. LIKE wildcards in the term are escaped so a stray
        // % or _ narrows literally instead of broadening the match.
        $search = trim((string) $request->string('search'));
        $searchScope = function ($q) use ($search): void {
            if ($search === '') {
                return;
            }
            $term = '%'.addcslashes($search, '%_\\').'%';
            $q->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('creator_credit', 'like', $term));
        };

        // Production filters, applied to BOTH the counts breakdown and the
        // paginator so the summary badges never contradict the visible rows.
        $filterScope = function ($q) use ($request): void {
            if ($request->filled('blocker')) {
                $q->whereJsonContains('cannot_publish_reasons', $request->string('blocker')->toString());
            }
            if ($request->filled('source')) {
                $q->where('source_kind', $request->string('source')->toString());
            }
            if ($request->filled('print_method')) {
                $q->where('print_method', $request->string('print_method')->toString());
            }
            if ($request->filled('category')) {
                $q->where('category', $request->string('category')->toString());
            }
            if ($request->boolean('ip_flagged')) {
                $q->where('ip_flagged', true);
            }
            if ($request->boolean('missing_link')) {
                // Blanks with no buy link to procure from (SCRAPED_UV only).
                // Empty-array detection is driver-portable: an empty json array
                // casts/stores as the literal '[]' on SQLite (test DB) and MySQL,
                // so we avoid JSON_LENGTH (needs the JSON1 extension on SQLite).
                $q->where('class', 'SCRAPED_UV')
                    ->where(fn ($w) => $w->whereNull('source_links')
                        ->orWhere('source_links', '[]')
                        ->orWhere('source_links', ''));
            }
        };

        // State breakdown across the WHOLE gate set (respecting the class + search
        // filters but NOT the state filter), so the summary badges reflect the
        // filtered catalogue total - not just the current page or the state subset.
        $byState = Product::query()
            ->whereIn('class', ['SCRAPED_UV', 'MODEL_3D'])
            // The gate is a review surface for not-yet-live items; published
            // products are managed from the product admin, so they're excluded
            // from both the list and these summary counts.
            ->where('publish_state', '!=', PublishState::Published->value)
            ->when($request->filled('class'), fn ($q) => $q->where('class', $request->string('class')->toString()))
            ->where($searchScope)
            ->where($filterScope)
            ->selectRaw('publish_state, COUNT(*) as c')
            ->groupBy('publish_state')
            ->pluck('c', 'publish_state');

        $counts = [
            'total' => (int) $byState->sum(),
            'pending' => (int) ($byState[PublishState::Pending->value] ?? 0),
            'ready' => (int) ($byState[PublishState::ReadyToApprove->value] ?? 0),
            'published' => (int) ($byState[PublishState::Published->value] ?? 0),
            'blocked' => (int) ($byState[PublishState::CannotPublish->value] ?? 0),
        ];

        // Sort + direction, mirroring the product admin (sort key + asc/desc dir).
        // Default 'newest' = creation date descending. Unknown keys fall back to
        // created_at; id is the stable tiebreaker so pages don't shuffle on ties.
        $sort = (string) $request->query('sort', 'newest');
        $dir = strtolower((string) $request->query('dir', ''));
        $dir = in_array($dir, ['asc', 'desc'], true) ? $dir : 'desc';

        $paginator = Product::query()
            ->whereIn('class', ['SCRAPED_UV', 'MODEL_3D'])
            ->where('publish_state', '!=', PublishState::Published->value)
            ->when($request->filled('class'), fn ($q) => $q->where('class', $request->string('class')->toString()))
            ->when($request->filled('state'), fn ($q) => $q->where('publish_state', $request->string('state')->toString()))
            ->where($searchScope)
            ->where($filterScope)
            ->when($sort === 'name', fn ($q) => $q->orderBy('name', $dir))
            ->when($sort === 'base_cost', fn ($q) => $q->orderBy('base_cost', $dir))
            ->when(! in_array($sort, ['name', 'base_cost'], true), fn ($q) => $q->orderBy('created_at', $dir))
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        $paginator->getCollection()->transform(fn (Product $p): array => [
            'id' => $p->id,
            'name' => $p->name,
            'class' => $p->class->value,
            'publish_state' => $p->publish_state->value,
            'cannot_publish_reasons' => $p->cannot_publish_reasons,
            // Prefill for the inline blocker-resolution popup (the fields the
            // scraped gate's reasons actually name).
            'weight' => $p->weight,
            'dimensions' => $p->dimensions,
            'print_method' => $p->print_method?->value,
            'is_printable' => (bool) $p->is_printable,
            'stock_estimate' => $p->stock_estimate,
            'base_cost' => $p->base_cost,
            'currency' => $p->currency,
            'creator_credit' => $p->creator_credit,
            'image_url' => $p->image_url,
            'source_url' => $p->source_url,
            'source_kind' => $p->source_kind,
            'filament_material' => $p->filament_material,
            'filament_color' => $p->filament_color,
            'est_grams' => $p->est_grams,
            'estimates_verified' => (bool) $p->estimates_verified,
            'model_file_ref' => $p->model_file_ref,
        ]);

        return response()->json([
            'data' => $paginator->items(),
            // Full-set state breakdown for the summary badges (page-independent).
            'counts' => $counts,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                // Surfaced so the admin UI can hydrate the auto-publish toggle
                // from the real server setting instead of defaulting to false.
                'auto_publish' => (bool) PricingConfig::value('catalogue', 'auto_publish', false),
            ],
        ]);
    }

    public function publish(Request $request, Product $product): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);

        // Only a completed/licence-cleared item awaiting approval can be published;
        // a CANNOT_PUBLISH item must be fixed/re-synced first.
        if ($product->publish_state !== PublishState::ReadyToApprove) {
            return response()->json(['message' => 'Product is not awaiting approval.'], 422);
        }

        // Route through the class's own service so publication is re-gated
        // (never trust the possibly-stale state flag).
        $product = $product->class === ProductClass::Model3d
            ? $this->model3d->publish($product)
            : $this->scraped->publish($product);

        if ($product->publish_state !== PublishState::Published) {
            return response()->json([
                'message' => 'Product failed completeness/licence checks and cannot be published.',
                'cannot_publish_reasons' => $product->cannot_publish_reasons,
            ], 422);
        }

        return response()->json(['publish_state' => $product->publish_state->value]);
    }

    /**
     * Staff fill in the facts the scraper couldn't read (dimensions + weight,
     * print method, price) from the gate itself, then re-gate and publish if the
     * row came fully clear. Deliberately narrow: it accepts ONLY the fields the
     * three self-fixable CompletenessGate reasons name, so it needs no
     * superadmin-field stripping the way the general product PATCH does.
     *
     * A 422 always means the INPUT was bad - never that the product merely
     * stayed blocked. Staying blocked is a 200 with published=false, so typed
     * work is never thrown away.
     */
    public function resolveBlockers(Request $request, Product $product): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);

        if ($product->class !== ProductClass::ScrapedUv) {
            return response()->json([
                'message' => 'Only SCRAPED_UV products resolve blockers here; 3D items have their own tools.',
            ], 422);
        }

        if (! in_array($product->publish_state, [PublishState::CannotPublish, PublishState::Pending], true)) {
            return response()->json(['message' => 'Product has no blockers to resolve.'], 422);
        }

        // Sanity ceilings (2 m, 100 kg, SGD 1M) catch a slipped decimal; they are
        // absurdity bounds, not business limits.
        $validated = $request->validate([
            'base_cost' => ['sometimes', 'numeric', 'gt:0', 'max:1000000'],
            'weight' => ['sometimes', 'numeric', 'gt:0', 'max:100000'],
            'dimensions' => ['sometimes', 'array'],
            'dimensions.l' => ['required_with:dimensions', 'numeric', 'gt:0', 'max:2000'],
            'dimensions.w' => ['required_with:dimensions', 'numeric', 'gt:0', 'max:2000'],
            'dimensions.h' => ['required_with:dimensions', 'numeric', 'gt:0', 'max:2000'],
            'print_method' => ['sometimes', 'string', 'in:UV,FDM,RESIN'],
            'is_printable' => ['sometimes', 'boolean'],
            // Manual stock the staffer reads off the source listing: the affiliate
            // feed never carries a quantity, so a fuller sync can't clear
            // stock_unreadable on its own. Indicative only (non-authoritative) -
            // the order-time StockLedger is what actually prevents overselling.
            'stock_estimate' => ['sometimes', 'integer', 'gt:0', 'max:1000000'],
        ]);

        if (isset($validated['dimensions'])) {
            $validated['dimensions'] = $validated['dimensions'] + ['unit' => 'mm'];
        }

        $before = [
            'base_cost' => $product->base_cost,
            'weight' => $product->weight,
            'dimensions' => $product->dimensions,
            'print_method' => $product->print_method?->value,
            'is_printable' => $product->is_printable,
            'stock_estimate' => $product->stock_estimate,
            'publish_state' => $product->publish_state->value,
        ];

        $product = DB::transaction(function () use ($product, $validated): Product {
            $product->fill($validated);
            $product->save();

            $product = $this->scraped->regate($product);

            // regate() never publishes on its own - a clean re-gate lands on
            // ReadyToApprove and we make the publish an explicit call, so the
            // gate is re-run (publish() re-checks completeness itself).
            if ($product->publish_state === PublishState::ReadyToApprove) {
                $product = $this->scraped->publish($product);
            }

            return $product;
        });

        $this->audit->log($product, 'product.blockers_resolved', $before, [
            'base_cost' => $product->base_cost,
            'weight' => $product->weight,
            'dimensions' => $product->dimensions,
            'print_method' => $product->print_method?->value,
            'is_printable' => $product->is_printable,
            'stock_estimate' => $product->stock_estimate,
            'publish_state' => $product->publish_state->value,
        ]);

        return response()->json([
            'published' => $product->publish_state === PublishState::Published,
            'publish_state' => $product->publish_state->value,
            'cannot_publish_reasons' => $product->cannot_publish_reasons,
        ]);
    }

    /**
     * Staff delete (archive) an unpublished gate item. Soft delete so it's
     * recoverable via the product-admin restore. Published products are refused -
     * they're live, managed from the product admin, and the gate no longer lists
     * them anyway.
     */
    public function destroy(Request $request, Product $product): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);

        if ($product->publish_state === PublishState::Published) {
            return response()->json(['message' => 'Unpublish a live product before deleting it.'], 422);
        }

        $product->delete();

        $this->audit->log($product, 'product.gate_deleted', ['publish_state' => $product->publish_state->value], null);

        return response()->json(['deleted' => true]);
    }

    /**
     * Staff bulk-delete (archive) unpublished gate items. Published rows are
     * skipped and counted as failed rather than aborting the whole batch, so a
     * stray live id in the selection can't block the rest. Soft delete.
     */
    public function bulkDestroy(Request $request): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);

        $validated = $request->validate([
            'ids' => ['required', 'array', 'max:200'],
            'ids.*' => ['integer'],
        ]);

        $deleted = 0;
        $failed = 0;

        foreach ($validated['ids'] as $id) {
            $product = Product::find($id);

            if ($product === null || $product->publish_state === PublishState::Published) {
                $failed++;

                continue;
            }

            $product->delete();
            $this->audit->log($product, 'product.gate_deleted', ['publish_state' => $product->publish_state->value], null);
            $deleted++;
        }

        return response()->json(['meta' => ['deleted' => $deleted, 'failed' => $failed]]);
    }

    public function unpublish(Request $request, Product $product): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);

        $product->class === ProductClass::Model3d
            ? $this->model3d->unpublish($product)
            : $this->scraped->unpublish($product);

        return response()->json(['publish_state' => $product->fresh()->publish_state->value]);
    }

    /**
     * Staff confirm a MODEL_3D item's production estimates (filament + grams)
     * that the source API could not provide. Clears the estimates_unverified
     * hold so the item can auto-publish or be approved.
     */
    public function verifyEstimates(Request $request, Product $product): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);

        if ($product->class !== ProductClass::Model3d) {
            return response()->json(['message' => 'Only MODEL_3D products carry filament estimates.'], 422);
        }

        $validated = $request->validate([
            'filament_material' => ['required', 'string', 'max:50'],
            'filament_color' => ['required', 'string', 'max:50'],
            'est_grams' => ['required', 'numeric', 'gt:0', 'lte:100000'],
        ]);

        $product = $this->model3d->verifyEstimates(
            $product,
            $validated['filament_material'],
            $validated['filament_color'],
            (float) $validated['est_grams'],
        );

        return response()->json([
            'publish_state' => $product->publish_state->value,
            'estimates_verified' => true,
            'cannot_publish_reasons' => $product->cannot_publish_reasons,
        ]);
    }

    /**
     * Staff attach the printable model file for a MODEL_3D item whose source
     * has no download API (e.g. Cults3D). Clears the missing_model_file hold
     * by re-running the gate.
     */
    public function uploadModelFile(Request $request, Product $product): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);

        if ($product->class !== ProductClass::Model3d) {
            return response()->json(['message' => 'Only MODEL_3D products carry a model file.'], 422);
        }

        $request->validate([
            // Content sniffing can't identify STL/3MF reliably; extension +
            // size bound is the practical gate for a staff-only endpoint.
            'file' => ['required', 'file', 'max:102400'], // 100 MB
        ]);

        $disk = (string) config('model3d.disk', 'local');

        $upload = $request->file('file');
        $extension = strtolower((string) $upload->getClientOriginalExtension());

        $meshExts = ['stl', '3mf', 'obj'];
        $isGlb = $extension === 'glb';

        if (! $isGlb && ! in_array($extension, $meshExts, true)) {
            return response()->json(['message' => 'Model file must be .stl, .3mf, .obj or .glb.'], 422);
        }

        if ($isGlb) {
            // GLB is display-only: replace the decoration model, leave the
            // canonical mesh + slicer estimates + zone untouched.
            $old = (string) ($product->decor_glb_ref ?? '');
            $path = $upload->storeAs('models3d', "decor-{$product->id}.glb", $disk);
            if ($old !== '' && $old !== $path && Storage::disk($disk)->exists($old)) {
                Storage::disk($disk)->delete($old);
            }
            $product->decor_glb_ref = $path;
            $product->save();

            return response()->json([
                'publish_state' => $product->publish_state->value,
                'model_file_ref' => $product->model_file_ref,
                'decor_glb_ref' => $product->decor_glb_ref,
            ]);
        }

        // Mesh replace: new geometry invalidates the slicer measurement AND any
        // marked print zone (the surface it referenced may no longer exist).
        $old = (string) ($product->model_file_ref ?? '');
        $path = $upload->storeAs('models3d', "manual-{$product->id}.{$extension}", $disk);
        if ($old !== '' && $old !== $path && ! str_starts_with($old, 'http') && Storage::disk($disk)->exists($old)) {
            Storage::disk($disk)->delete($old);
        }

        // A manual single-file mesh supersedes any scraped multi-part set, so drop
        // the recorded parts (and their files) - otherwise the stale is_primary
        // row would point at the file we just deleted, skewing model_file_ref.
        $this->clearModelParts($product);

        $product->model_file_ref = $path;
        $product->is_printable = true;
        $product->estimates_verified = false;
        $product->print_zone = null;
        $product->save();

        $product = $this->model3d->regate($product);

        return response()->json([
            'publish_state' => $product->publish_state->value,
            'model_file_ref' => $product->model_file_ref,
            'decor_glb_ref' => $product->decor_glb_ref,
        ]);
    }

    /**
     * Persist the admin-marked (or auto-detected) print zone for a MODEL_3D
     * product. Model-space normal + center + up + size (mm); the single source
     * of truth for the customer decal preview and the production print file.
     */
    public function savePrintZone(Request $request, Product $product): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);

        if ($product->class !== ProductClass::Model3d) {
            return response()->json(['message' => 'Only MODEL_3D products carry a print zone.'], 422);
        }

        $validated = $request->validate([
            'print_zone' => ['required', 'array'],
            'print_zone.normal' => ['required', 'array', 'size:3'],
            'print_zone.normal.*' => ['required', 'numeric'],
            'print_zone.center' => ['required', 'array', 'size:3'],
            'print_zone.center.*' => ['required', 'numeric'],
            'print_zone.up' => ['required', 'array', 'size:3'],
            'print_zone.up.*' => ['required', 'numeric'],
            'print_zone.width_mm' => ['required', 'numeric', 'gt:0'],
            'print_zone.height_mm' => ['required', 'numeric', 'gt:0'],
        ]);

        $product->print_zone = $validated['print_zone'];
        $product->save();

        return response()->json(['print_zone' => $product->print_zone]);
    }

    /**
     * Staff-only model stream for the admin zone editor + decal preview, served
     * for ANY publish state (the public CatalogueController::model requires a
     * public product). `kind=glb` serves the authored decoration GLB when set;
     * anything else serves the canonical mesh (STL/3MF/OBJ).
     */
    public function adminModel(Request $request, Product $product): StreamedResponse|JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);

        $disk = (string) config('model3d.disk', 'local');

        $kind = $request->string('kind')->toString();
        $ref = $kind === 'glb'
            ? (string) ($product->decor_glb_ref ?? '')
            : (string) ($product->model_file_ref ?? '');

        if ($ref === '' || str_starts_with($ref, 'http') || ! Storage::disk($disk)->exists($ref)) {
            return response()->json(['message' => 'Model not available.'], 404);
        }

        return Storage::disk($disk)->response($ref, basename($ref), [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Stream the print-floor production file for a MODEL_3D product: the
     * H2S-targeted .3mf when present (production_file_ref), otherwise the model
     * file itself (Thingiverse prints the STL directly). Staff-only. This is the
     * "hand it to the printer" download the production queue offers.
     */
    public function productionFile(Request $request, Product $product): StreamedResponse|JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);

        // Prefer a dedicated production file on the production disk; fall back to
        // the model file when none exists (production_file_ref falls back to
        // model_file_ref by design).
        $ref = (string) ($product->production_file_ref ?? '');
        if ($ref !== '' && ! str_starts_with($ref, 'http')) {
            $disk = (string) config('model3d.production_disk', 'local');
        } else {
            $ref = (string) ($product->model_file_ref ?? '');
            $disk = (string) config('model3d.disk', 'local');
        }

        if ($ref === '' || str_starts_with($ref, 'http') || ! Storage::disk($disk)->exists($ref)) {
            return response()->json(['message' => 'No production file available.'], 404);
        }

        $store = Storage::disk($disk);

        return $store->response($ref, basename($ref), [
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => (int) $store->size($ref),
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Stream one part's STL mesh for the superadmin multi-part viewer. Staff-only
     * and scoped to the parent product so a part id can't be used to reach an
     * unrelated product's file.
     */
    public function partModel(Request $request, Product $product, ProductModelPart $part): StreamedResponse|JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);
        abort_unless($part->product_id === $product->id, 404);

        $disk = (string) config('model3d.disk', 'local');

        $ref = (string) $part->file_ref;
        if ($ref === '' || str_starts_with($ref, 'http') || ! Storage::disk($disk)->exists($ref)) {
            return response()->json(['message' => 'Part not available.'], 404);
        }

        return Storage::disk($disk)->response($ref, basename($ref), [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Stream a ZIP of the selected printable plates for the print floor.
     * `part_ids` lists the model parts to include; when empty (a single-mesh
     * product with no separate parts) the product's primary model file is
     * exported instead. Visualisation aside, this is the "hand it to the slicer"
     * step - the floor loads these STLs into their own slicer to produce
     * printer G-code (the app does not slice).
     */
    public function exportParts(Request $request, Product $product): StreamedResponse|JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);

        $disk = (string) config('model3d.disk', 'local');

        $ids = collect($request->input('part_ids', []))
            ->map(fn ($i): int => (int) $i)
            ->filter()
            ->unique()
            ->all();

        /** @var array<string, string> $files name => absolute local path */
        $files = [];
        /** @var list<callable(): void> $cleanups materialised-copy removers (no-op on local) */
        $cleanups = [];

        if ($ids !== []) {
            $parts = $product->modelParts()->whereIn('id', $ids)->orderBy('sort')->get();
            foreach ($parts as $part) {
                $this->collectExportFile($files, $cleanups, $disk, (string) $part->file_ref, $part->label ?? 'part');
            }
        } else {
            $this->collectExportFile($files, $cleanups, $disk, (string) $product->model_file_ref, $product->name);
        }

        if ($files === []) {
            return response()->json(['message' => 'No exportable model files.'], 404);
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'plates').'.zip';
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            foreach ($cleanups as $cleanup) {
                $cleanup();
            }

            return response()->json(['message' => 'Could not build the export.'], 500);
        }
        foreach ($files as $name => $path) {
            $zip->addFile($path, $name);
        }
        // ZipArchive reads the source bytes at close() time, so once the archive
        // is written the materialised input copies (S3 temp files) are no longer
        // needed and can be cleaned before the zip itself is streamed below.
        $zip->close();
        foreach ($cleanups as $cleanup) {
            $cleanup();
        }

        $download = Str::slug($product->name ?: 'model').'-plates.zip';

        return response()->streamDownload(function () use ($zipPath): void {
            readfile($zipPath);
            @unlink($zipPath);
        }, $download, [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Add a stored (existing) model file to the export map under a unique, slugged
     * filename that keeps its extension. Remote-ref / missing files skip. The file
     * is materialised to a real local path via ModelFileAccess (a no-op copy on a
     * local disk, a temp copy on S3); its cleanup is appended to $cleanups for the
     * caller to run once the zip has been written.
     *
     * @param  array<string, string>  $files
     * @param  list<callable(): void>  $cleanups
     */
    private function collectExportFile(array &$files, array &$cleanups, string $disk, string $ref, string $label): void
    {
        if ($ref === '' || str_starts_with($ref, 'http') || ! Storage::disk($disk)->exists($ref)) {
            return;
        }

        $ext = strtolower(pathinfo($ref, PATHINFO_EXTENSION) ?: 'stl');
        $base = Str::slug($label) ?: 'part';
        $name = "{$base}.{$ext}";
        $n = 2;
        while (isset($files[$name])) {
            $name = "{$base}-{$n}.{$ext}";
            $n++;
        }

        [$path, $cleanup] = ModelFileAccess::localPath($disk, $ref);
        $files[$name] = $path;
        $cleanups[] = $cleanup;
    }

    /** Printable mesh formats a staff member may attach as a part. */
    private const PART_EXTENSIONS = ['stl', '3mf', 'obj'];

    /**
     * Superadmin attach an extra mesh part to a multi-part product (e.g. a missing
     * limb the scrape never shipped). Accepts the same printable formats as the
     * primary mesh (.stl/.3mf/.obj); the in-browser 3D viewer only renders STL,
     * but the floor can still download any format. Stored under the product's
     * part namespace.
     */
    public function uploadModelPart(Request $request, Product $product): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);

        if ($product->class !== ProductClass::Model3d) {
            return response()->json(['message' => 'Only MODEL_3D products carry model parts.'], 422);
        }

        $request->validate([
            'file' => ['required', 'file', 'max:102400'], // 100 MB
            'label' => ['nullable', 'string', 'max:120'],
        ]);

        $upload = $request->file('file');
        $ext = strtolower((string) $upload->getClientOriginalExtension());
        if (! in_array($ext, self::PART_EXTENSIONS, true)) {
            return response()->json(['message' => 'A model part must be an .stl, .3mf or .obj file.'], 422);
        }

        $disk = (string) config('model3d.disk', 'local');
        $nextSort = (int) ($product->modelParts()->max('sort') ?? -1) + 1;
        $path = $upload->storeAs('models3d', "manual-{$product->id}-part{$nextSort}.{$ext}", $disk);

        $label = trim((string) $request->input('label', ''));
        $part = $product->modelParts()->create([
            'label' => $label !== '' ? $label : 'Part '.($nextSort + 1),
            'file_ref' => $path,
            // Manual uploads are supplementary; the scraped/primary mesh stays primary.
            'is_primary' => false,
            'sort' => $nextSort,
        ]);

        return response()->json(['data' => $this->serializePart($part)], 201);
    }

    /**
     * Superadmin choose which stored part is the primary mesh. The primary
     * mirrors products.model_file_ref (the mesh the slicer prints and the PDP
     * previews), so this flips the is_primary flag and repoints model_file_ref,
     * then recomputes dimensions from the new geometry.
     */
    public function setPrimaryPart(Request $request, Product $product, ProductModelPart $part): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);
        abort_unless($part->product_id === $product->id, 404);

        $disk = (string) config('model3d.disk', 'local');

        $ref = (string) $part->file_ref;
        if ($ref === '' || str_starts_with($ref, 'http') || ! Storage::disk($disk)->exists($ref)) {
            return response()->json(['message' => 'That part has no stored file to make primary.'], 422);
        }

        DB::transaction(function () use ($product, $part, $ref): void {
            $product->modelParts()->update(['is_primary' => false]);
            $part->is_primary = true;
            $part->save();

            $product->model_file_ref = $ref;
            $product->dimensions = null; // recompute from the new primary geometry
            $product->save();
            $this->model3d->fillDimensionsFromModel($product);
            $product->save();
        });

        return response()->json(['model_file_ref' => $product->model_file_ref]);
    }

    /**
     * Superadmin pull the latest model straight from its source (Thingiverse /
     * Cults3D), refreshing geometry, parts and dimensions - a per-product
     * forced re-ingest (the same path the nightly resync uses on a version
     * change). Owned/manual items have no upstream and are refused.
     */
    public function pullFromSource(Request $request, Product $product): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);

        if ($product->class !== ProductClass::Model3d) {
            return response()->json(['message' => 'Only MODEL_3D products pull from a source.'], 422);
        }

        $model = $product->model3d;
        if ($model === null || $model->source === null || $model->source->value === 'OWNED') {
            return response()->json(['message' => 'This product has no upstream source to pull from.'], 422);
        }

        $data = $this->apiClient->fetch($model->source, (string) $model->source_id);
        if ($data === null) {
            return response()->json(['message' => 'The source did not return this model (removed or rate-limited).'], 422);
        }

        ['product' => $product] = $this->model3d->ingest($data, forceFileRefresh: true);

        return response()->json([
            'publish_state' => $product->publish_state->value,
            'model_file_ref' => $product->model_file_ref,
        ]);
    }

    /**
     * Superadmin remove a part. The primary part is refused here - it mirrors
     * products.model_file_ref and is replaced via the model-file upload instead.
     */
    public function deleteModelPart(Request $request, Product $product, ProductModelPart $part): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);
        abort_unless($part->product_id === $product->id, 404);

        if ($part->is_primary) {
            return response()->json(['message' => 'The primary mesh cannot be removed here; replace it via the model file.'], 422);
        }

        $disk = (string) config('model3d.disk', 'local');
        $ref = (string) $part->file_ref;
        if ($ref !== '' && ! str_starts_with($ref, 'http') && Storage::disk($disk)->exists($ref)) {
            Storage::disk($disk)->delete($ref);
        }
        $part->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePart(ProductModelPart $part): array
    {
        return [
            'id' => $part->id,
            'label' => $part->label,
            'triangle_count' => $part->triangle_count,
            'is_primary' => $part->is_primary,
            'sort' => $part->sort,
        ];
    }

    /**
     * Drop the recorded multi-part decomposition and its non-primary files. Used
     * when the primary mesh is replaced wholesale, so model_file_ref stays the
     * single source of truth for the printable geometry. The primary part shares
     * model_file_ref (deleted by the caller), so only the extra part files are
     * removed here.
     */
    private function clearModelParts(Product $product): void
    {
        $disk = (string) config('model3d.disk', 'local');
        foreach ($product->modelParts()->get() as $part) {
            $ref = (string) $part->file_ref;
            if ($ref !== '' && ! $part->is_primary && ! str_starts_with($ref, 'http') && Storage::disk($disk)->exists($ref)) {
                Storage::disk($disk)->delete($ref);
            }
        }
        $product->modelParts()->delete();
    }

    public function setAutoPublish(Request $request): JsonResponse
    {
        abort_unless($request->user()->isSuperadmin(), 403);

        $validated = $request->validate(['enabled' => ['required', 'boolean']]);

        PricingConfig::updateOrCreate(
            ['group' => 'catalogue', 'key' => 'auto_publish'],
            ['value' => $validated['enabled'], 'label' => 'Auto-publish complete scraped/3D items', 'is_money' => false, 'currency' => 'SGD', 'updated_by' => $request->user()->id],
        );

        return response()->json(['auto_publish' => $validated['enabled']]);
    }
}
