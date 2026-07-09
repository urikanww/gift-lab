<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ProductClass;
use App\Enums\PublishState;
use App\Models\PricingConfig;
use App\Models\Product;
use App\Models\ProductModelPart;
use App\Services\Catalogue\ScrapedCatalogueService;
use App\Services\Model3d\Contracts\Model3dApiClient;
use App\Services\Model3d\Model3dCatalogueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);

        // Bounded pagination (matches the public CatalogueController) - the admin
        // gate previously did an unbounded ->get() over all SCRAPED_UV + MODEL_3D
        // rows, so response size/memory grew linearly with scraped inventory.
        $perPage = max(1, min((int) $request->integer('per_page', 24), 100));

        // State breakdown across the WHOLE gate set (respecting the class filter
        // but NOT the state filter), so the summary badges reflect the full
        // catalogue total - not just the current page or the filtered subset.
        $byState = Product::query()
            ->whereIn('class', ['SCRAPED_UV', 'MODEL_3D'])
            ->when($request->filled('class'), fn ($q) => $q->where('class', $request->string('class')->toString()))
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

        $paginator = Product::query()
            ->whereIn('class', ['SCRAPED_UV', 'MODEL_3D'])
            ->when($request->filled('class'), fn ($q) => $q->where('class', $request->string('class')->toString()))
            ->when($request->filled('state'), fn ($q) => $q->where('publish_state', $request->string('state')->toString()))
            ->orderByDesc('updated_at')
            ->paginate($perPage);

        $paginator->getCollection()->transform(fn (Product $p): array => [
            'id' => $p->id,
            'name' => $p->name,
            'class' => $p->class->value,
            'publish_state' => $p->publish_state->value,
            'cannot_publish_reasons' => $p->cannot_publish_reasons,
            'base_cost' => $p->base_cost,
            'currency' => $p->currency,
            'creator_credit' => $p->creator_credit,
            'image_url' => $p->image_url,
            'source_url' => $p->source_url,
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
            $path = $upload->storeAs('models3d', "decor-{$product->id}.glb", 'local');
            if ($old !== '' && $old !== $path && Storage::disk('local')->exists($old)) {
                Storage::disk('local')->delete($old);
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
        $path = $upload->storeAs('models3d', "manual-{$product->id}.{$extension}", 'local');
        if ($old !== '' && $old !== $path && ! str_starts_with($old, 'http') && Storage::disk('local')->exists($old)) {
            Storage::disk('local')->delete($old);
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

        $kind = $request->string('kind')->toString();
        $ref = $kind === 'glb'
            ? (string) ($product->decor_glb_ref ?? '')
            : (string) ($product->model_file_ref ?? '');

        if ($ref === '' || str_starts_with($ref, 'http') || ! Storage::disk('local')->exists($ref)) {
            return response()->json(['message' => 'Model not available.'], 404);
        }

        return Storage::disk('local')->response($ref, basename($ref), [
            'Content-Type' => 'application/octet-stream',
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

        $ref = (string) $part->file_ref;
        if ($ref === '' || str_starts_with($ref, 'http') || ! Storage::disk('local')->exists($ref)) {
            return response()->json(['message' => 'Part not available.'], 404);
        }

        return Storage::disk('local')->response($ref, basename($ref), [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'no-store',
        ]);
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

        $nextSort = (int) ($product->modelParts()->max('sort') ?? -1) + 1;
        $path = $upload->storeAs('models3d', "manual-{$product->id}-part{$nextSort}.{$ext}", 'local');

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

        $ref = (string) $part->file_ref;
        if ($ref === '' || str_starts_with($ref, 'http') || ! Storage::disk('local')->exists($ref)) {
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

        $ref = (string) $part->file_ref;
        if ($ref !== '' && ! str_starts_with($ref, 'http') && Storage::disk('local')->exists($ref)) {
            Storage::disk('local')->delete($ref);
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
        foreach ($product->modelParts()->get() as $part) {
            $ref = (string) $part->file_ref;
            if ($ref !== '' && ! $part->is_primary && ! str_starts_with($ref, 'http') && Storage::disk('local')->exists($ref)) {
                Storage::disk('local')->delete($ref);
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
