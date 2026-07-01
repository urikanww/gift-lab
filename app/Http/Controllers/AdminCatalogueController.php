<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PublishState;
use App\Models\PricingConfig;
use App\Models\Product;
use App\Services\Catalogue\ScrapedCatalogueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Superadmin/staff catalogue gate (spec 6.7): review scraped + 3D items, approve
 * for publication, pull from public, and toggle global auto-publish. Staff-only;
 * the auto-publish setting is superadmin-only.
 */
class AdminCatalogueController extends Controller
{
    public function __construct(private readonly ScrapedCatalogueService $scraped)
    {
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

        // Route through the service so publication is re-gated by CompletenessGate
        // (was set to Published directly here, trusting the possibly-stale state
        // flag and bypassing the completeness/licence check).
        $product = $this->scraped->publish($product);

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

        $this->scraped->unpublish($product);

        return response()->json(['publish_state' => $product->fresh()->publish_state->value]);
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
