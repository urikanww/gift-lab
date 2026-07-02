<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ProductClass;
use App\Enums\PublishState;
use App\Models\PricingConfig;
use App\Models\Product;
use App\Services\Catalogue\ScrapedCatalogueService;
use App\Services\Model3d\Model3dCatalogueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Superadmin/staff catalogue gate (spec 6.7): review scraped + 3D items, approve
 * for publication, pull from public, and toggle global auto-publish. Staff-only;
 * the auto-publish setting is superadmin-only. Publish/unpublish route through
 * the class's own service — the scraped CompletenessGate checks price/dims/stock
 * that MODEL_3D items structurally never have, so 3D items get the 3D gate
 * (licence + credit + local file + verified estimates).
 */
class AdminCatalogueController extends Controller
{
    public function __construct(
        private readonly ScrapedCatalogueService $scraped,
        private readonly Model3dCatalogueService $model3d,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);

        // Bounded pagination (matches the public CatalogueController) — the admin
        // gate previously did an unbounded ->get() over all SCRAPED_UV + MODEL_3D
        // rows, so response size/memory grew linearly with scraped inventory.
        $perPage = max(1, min((int) $request->integer('per_page', 24), 100));

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

        if (! in_array($extension, ['stl', '3mf', 'obj'], true)) {
            return response()->json(['message' => 'Model file must be .stl, .3mf or .obj.'], 422);
        }

        $path = $upload->storeAs('models3d', "manual-{$product->id}.{$extension}", 'local');

        $product->model_file_ref = $path;
        $product->is_printable = true;
        // New geometry invalidates any previous slicer measurement.
        $product->estimates_verified = false;
        $product->save();

        // Re-run the gate so the missing_model_file hold clears.
        $product = $this->model3d->regate($product);

        return response()->json([
            'publish_state' => $product->publish_state->value,
            'model_file_ref' => $product->model_file_ref,
        ]);
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
