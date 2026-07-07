<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\License;
use App\Enums\ProductClass;
use App\Enums\PublishState;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Variant;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Staff CRUD over CORE products and their variants (audit E4): ops can add a
 * new blank with variants + stock, or fix a price, without touching seeders or
 * the DB. Scraped/3D items keep their own ingest + gate flows — this surface
 * manages the CORE class only.
 */
class AdminProductController extends Controller
{
    /**
     * Quote states that mean the customer committed to the order (spec: "won"
     * for the sold_count metric). Excludes DRAFT/SENT/CHANGES_REQUESTED (not
     * yet committed) and CANCELLED (committed then withdrawn).
     *
     * @var array<int, string>
     */
    private const WON_QUOTE_STATES = [
        'ACCEPTED',
        'PROOFING',
        'PROOF_APPROVED',
        'PO_ISSUED',
        'CONFIRMED',
        'PROCURING',
        'READY',
        'CLOSED',
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AdminCatalogueController $catalogue,
    ) {}

    private const PRODUCT_RULES = [
        'name' => ['required', 'string', 'max:255'],
        'description' => ['nullable', 'string', 'max:5000'],
        'base_cost' => ['required', 'numeric', 'gt:0'],
        'weight' => ['required', 'numeric', 'gt:0'],
        'dimensions' => ['required', 'array'],
        'dimensions.l' => ['required', 'numeric', 'gt:0'],
        'dimensions.w' => ['required', 'numeric', 'gt:0'],
        'dimensions.h' => ['required', 'numeric', 'gt:0'],
        'print_method' => ['required', 'string', 'in:UV,FDM,RESIN'],
        'stock_mode' => ['required', 'string', 'in:STOCKED,MAKE_TO_ORDER'],
        'category' => ['nullable', 'string', 'max:100'],
        'image_url' => ['nullable', 'url', 'max:2048'],
        'is_printable' => ['nullable', 'boolean'],
        'publish_state' => ['nullable', 'string', 'in:PENDING,PUBLISHED'],
    ];

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);

        $perPage = max(1, min((int) $request->integer('per_page', 100), 200));

        // status: active (default, live rows) | archived (soft-deleted) | all.
        $status = (string) $request->query('status', 'active');
        $class = (string) $request->query('class', '');
        $q = trim((string) $request->query('q', ''));
        $publishState = (string) $request->query('publish_state', '');
        $category = (string) $request->query('category', '');
        $licenseTier = (string) $request->query('license_tier', '');
        $sort = (string) $request->query('sort', 'newest');

        // Licence-tier filter maps a tier back to its licence codes (derived,
        // not a column). The "standard" tier also covers a null licence (CORE).
        $tierCodes = in_array($licenseTier, ['standard', 'extended', 'high_risk'], true)
            ? array_values(array_map(
                fn (License $l): string => $l->value,
                array_filter(License::cases(), fn (License $l): bool => $l->tier() === $licenseTier),
            ))
            : [];

        // Correlated subquery: qty summed across line_items whose parent quote
        // is in a "won" state, for this product. Drives both the sold_count
        // column and the most_sold sort (a single aggregate, not N+1).
        $soldCountSql = DB::table('line_items')
            ->join('quotes', 'quotes.id', '=', 'line_items.quote_id')
            ->whereColumn('line_items.product_id', 'products.id')
            ->whereNull('line_items.deleted_at')
            ->whereNull('quotes.deleted_at')
            ->whereIn('quotes.state', self::WON_QUOTE_STATES)
            ->selectRaw('coalesce(sum(line_items.qty), 0)');

        $defaultDirs = [
            'newest' => 'desc',
            'most_sold' => 'desc',
            'name' => 'asc',
            'base_cost' => 'asc',
            'stock' => 'desc',
        ];
        $dir = strtolower((string) $request->query('dir', ''));
        $dir = in_array($dir, ['asc', 'desc'], true) ? $dir : ($defaultDirs[$sort] ?? 'desc');

        $paginator = Product::query()
            ->when($status === 'archived', fn ($qr) => $qr->onlyTrashed())
            ->when($status === 'all', fn ($qr) => $qr->withTrashed())
            ->when(
                in_array($class, ['CORE', 'SCRAPED_UV', 'MODEL_3D'], true),
                fn ($qr) => $qr->where('class', $class),
            )
            ->when($q !== '', fn ($qr) => $qr->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($q).'%']))
            ->when(
                in_array($publishState, ['PENDING', 'READY_TO_APPROVE', 'PUBLISHED', 'CANNOT_PUBLISH'], true),
                fn ($qr) => $qr->where('publish_state', $publishState),
            )
            ->when($category !== '', fn ($qr) => $qr->where('category', $category))
            ->when($tierCodes !== [], fn ($qr) => $qr->where(function ($w) use ($tierCodes, $licenseTier): void {
                $w->whereIn('license', $tierCodes);
                if ($licenseTier === 'standard') {
                    $w->orWhereNull('license');
                }
            }))
            ->with('variants')
            ->withSum('variants', 'stock_on_hand')
            ->selectSub($soldCountSql, 'sold_count')
            ->when($sort === 'most_sold', fn ($qr) => $qr->orderBy('sold_count', $dir))
            ->when($sort === 'name', fn ($qr) => $qr->orderBy('name', $dir))
            ->when($sort === 'base_cost', fn ($qr) => $qr->orderBy('base_cost', $dir))
            ->when($sort === 'stock', fn ($qr) => $qr->orderBy('variants_sum_stock_on_hand', $dir))
            ->when(! in_array($sort, ['most_sold', 'name', 'base_cost', 'stock'], true), fn ($qr) => $qr->orderBy('created_at', $dir))
            ->paginate($perPage);

        return response()->json([
            'data' => collect($paginator->items())->map(fn (Product $p): array => $this->serialize($p)),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Single product for the detail/edit page. Bound withTrashed so an archived
     * product still resolves (the editor can view/restore it). Loads variants and
     * the same sold_count aggregate the list uses.
     */
    public function show(Request $request, Product $product): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);

        $product->load('variants')->loadSum('variants', 'stock_on_hand');
        $product->sold_count = (int) DB::table('line_items')
            ->join('quotes', 'quotes.id', '=', 'line_items.quote_id')
            ->where('line_items.product_id', $product->id)
            ->whereNull('line_items.deleted_at')
            ->whereNull('quotes.deleted_at')
            ->whereIn('quotes.state', self::WON_QUOTE_STATES)
            ->sum('line_items.qty');

        return response()->json(['data' => $this->serialize($product)]);
    }

    /**
     * Full change history for a product: every audit-logged change to the
     * product itself and its variants (edits, stock, publish, image), newest
     * first. Bound withTrashed so an archived product still resolves.
     */
    public function history(Request $request, Product $product): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);

        $variantIds = $product->variants()->withTrashed()->pluck('id')->all();

        $paginator = AuditLog::query()
            ->with('user:id,name')
            ->where(function ($q) use ($product, $variantIds): void {
                $q->where(fn ($w) => $w
                    ->where('auditable_type', Product::class)
                    ->where('auditable_id', $product->id));
                if ($variantIds !== []) {
                    $q->orWhere(fn ($w) => $w
                        ->where('auditable_type', Variant::class)
                        ->whereIn('auditable_id', $variantIds));
                }
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(max(1, min((int) $request->integer('per_page', 20), 100)));

        return response()->json([
            'data' => collect($paginator->items())->map(fn (AuditLog $log): array => [
                'id' => $log->id,
                'event' => $log->event,
                'entity' => class_basename((string) $log->auditable_type),
                'user' => $log->user?->name,
                'old_values' => $log->old_values,
                'new_values' => $log->new_values,
                'created_at' => $log->created_at?->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Bulk-publish a batch of READY_TO_APPROVE products. Reuses
     * AdminCatalogueController::publish() per item so the full completeness/
     * licence gate runs exactly as it does for a single publish — this never
     * duplicates that logic.
     */
    public function bulkPublish(Request $request): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);

        $validated = $request->validate([
            'ids' => ['required', 'array', 'max:200'],
            'ids.*' => ['integer'],
        ]);

        $results = [];
        $published = 0;
        $failed = 0;

        foreach ($validated['ids'] as $id) {
            try {
                $product = Product::find($id);

                if ($product === null) {
                    $results[] = ['id' => $id, 'ok' => false, 'error' => 'not found'];
                    $failed++;

                    continue;
                }

                if ($product->publish_state !== PublishState::ReadyToApprove) {
                    $results[] = ['id' => $id, 'ok' => false, 'error' => 'not eligible'];
                    $failed++;

                    continue;
                }

                $response = $this->catalogue->publish($request, $product);

                if ($response->getStatusCode() === 200) {
                    $results[] = ['id' => $id, 'ok' => true];
                    $published++;
                } else {
                    $message = (string) ($response->getData(true)['message'] ?? 'publish failed');
                    $results[] = ['id' => $id, 'ok' => false, 'error' => $message];
                    $failed++;
                }
            } catch (Throwable $e) {
                $results[] = ['id' => $id, 'ok' => false, 'error' => $e->getMessage()];
                $failed++;
            }
        }

        return response()->json([
            'data' => $results,
            'meta' => ['published' => $published, 'failed' => $failed],
        ]);
    }

    /**
     * Upload/replace a product's photo. Stored on the public disk under a
     * deterministic name so re-uploads simply overwrite the previous file
     * (same pattern the seeder uses for CORE product photography).
     */
    public function uploadImage(Request $request, Product $product): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);

        $validated = $request->validate([
            'image' => ['required', 'file', 'mimes:jpeg,png,webp', 'max:4096'],
        ]);

        $extension = $validated['image']->getClientOriginalExtension() ?: $validated['image']->extension();
        $path = "products/product-{$product->id}.{$extension}";

        Storage::disk('public')->putFileAs('products', $validated['image'], basename($path));

        $before = ['image_url' => $product->image_url];
        $product->image_url = url('storage/'.$path);
        $product->save();

        $this->audit->log($product, 'product.image_updated', $before, ['image_url' => $product->image_url]);

        return response()->json(['data' => $this->serialize($product->fresh(['variants']))]);
    }

    /**
     * Remove a product's photo. Only deletes the underlying file when it
     * actually lives on our public disk (defensive: some rows may still carry
     * an externally-hosted URL from before local hosting).
     */
    public function removeImage(Request $request, Product $product): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);

        $before = ['image_url' => $product->image_url];
        $publicPrefix = url('storage/');

        if ($product->image_url !== null && str_starts_with($product->image_url, $publicPrefix)) {
            $relativePath = ltrim(substr($product->image_url, strlen($publicPrefix)), '/');

            if ($relativePath !== '' && Storage::disk('public')->exists($relativePath)) {
                Storage::disk('public')->delete($relativePath);
            }
        }

        $product->image_url = null;
        $product->save();

        $this->audit->log($product, 'product.image_removed', $before, ['image_url' => null]);

        return response()->json(['data' => $this->serialize($product->fresh(['variants']))]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);

        $validated = $request->validate(self::PRODUCT_RULES);

        $product = Product::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'class' => ProductClass::Core->value,
            'base_cost' => $validated['base_cost'],
            'currency' => 'SGD',
            'dimensions' => $validated['dimensions'] + ['unit' => 'mm'],
            'weight' => $validated['weight'],
            'print_method' => $validated['print_method'],
            'stock_mode' => $validated['stock_mode'],
            'category' => $validated['category'] ?? null,
            'image_url' => $validated['image_url'] ?? null,
            'is_printable' => $validated['is_printable'] ?? true,
            // New blanks land unpublished by default; publishing is an
            // explicit act (spec 6.7 oversight).
            'publish_state' => $validated['publish_state'] ?? PublishState::Pending->value,
            'created_by' => $request->user()->id,
        ]);

        $this->audit->log($product, 'product.created', null, ['name' => $product->name, 'base_cost' => $product->base_cost]);

        return response()->json(['data' => $this->serialize($product)], 201);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);

        // Staff can edit any class here (the unified product manager). Scraped/3D
        // items still have their own ingest + gate flows; edits made here win.
        // All fields optional on update; validate only what was sent.
        $rules = array_map(
            static fn (array $rule): array => array_map(
                static fn ($r) => $r === 'required' ? 'sometimes' : $r,
                $rule,
            ),
            self::PRODUCT_RULES,
        );
        $rules['publish_state'] = ['nullable', 'string', Rule::in(['PENDING', 'PUBLISHED'])];
        // Editing (unlike creating a CORE blank) may target a MODEL_3D item whose
        // base_cost is legitimately 0 (priced dynamically), so allow 0 here.
        $rules['base_cost'] = ['sometimes', 'numeric', 'min:0'];
        $validated = $request->validate($rules);

        if (isset($validated['dimensions'])) {
            $validated['dimensions'] = $validated['dimensions'] + ['unit' => 'mm'];
        }

        $before = [
            'name' => $product->name,
            'base_cost' => $product->base_cost,
            'publish_state' => $product->publish_state->value,
        ];
        $product->fill($validated);
        $product->save();

        $this->audit->log($product, 'product.updated', $before, [
            'name' => $product->name,
            'base_cost' => $product->base_cost,
            'publish_state' => $product->publish_state->value,
        ]);

        return response()->json(['data' => $this->serialize($product->fresh(['variants']))]);
    }

    /**
     * Archive a product (soft delete). It drops out of the storefront and the
     * order spine immediately but stays recoverable via restore(); the model
     * cascades the soft-delete to its variants.
     */
    public function destroy(Request $request, Product $product): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);

        $product->delete();

        $this->audit->log($product, 'product.archived', ['publish_state' => $product->publish_state->value], null);

        return response()->json(['data' => $this->serialize($product->fresh(['variants']))]);
    }

    /**
     * Restore an archived product (and its cascaded variants). Bound with
     * withTrashed() on the route so the soft-deleted row resolves.
     */
    public function restore(Request $request, Product $product): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);

        if (! $product->trashed()) {
            return response()->json(['message' => 'Product is not archived.'], 422);
        }

        $product->restore();

        $this->audit->log($product, 'product.restored', null, ['publish_state' => $product->publish_state->value]);

        return response()->json(['data' => $this->serialize($product->fresh(['variants']))]);
    }

    public function storeVariant(Request $request, Product $product): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);

        // Superadmin has full variant control across all classes (e.g. filament
        // colours for a MODEL_3D item), not just CORE blanks.
        $validated = $request->validate([
            'attributes' => ['required', 'array', 'min:1'],
            'sku' => ['nullable', 'string', 'max:100'],
            'stock_on_hand' => ['required', 'integer', 'min:0'],
            'reorder_threshold' => ['nullable', 'integer', 'min:0'],
            'price_delta' => ['nullable', 'numeric'],
        ]);

        $variant = Variant::create([
            'product_id' => $product->id,
            'attributes' => $validated['attributes'],
            'sku' => $validated['sku'] ?? null,
            'stock_on_hand' => $validated['stock_on_hand'],
            'reorder_threshold' => $validated['reorder_threshold'] ?? 0,
            'price_delta' => $validated['price_delta'] ?? 0,
            'currency' => 'SGD',
        ]);

        $this->audit->log($variant, 'variant.created', null, [
            'product_id' => $product->id,
            'stock_on_hand' => $variant->stock_on_hand,
            'price_delta' => $variant->price_delta,
        ]);

        return response()->json(['data' => $variant], 201);
    }

    public function updateVariant(Request $request, Variant $variant): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);

        $validated = $request->validate([
            'attributes' => ['sometimes', 'array', 'min:1'],
            'sku' => ['nullable', 'string', 'max:100'],
            'stock_on_hand' => ['sometimes', 'integer', 'min:0'],
            'reorder_threshold' => ['sometimes', 'integer', 'min:0'],
            'price_delta' => ['sometimes', 'numeric'],
        ]);

        $before = [
            'stock_on_hand' => $variant->stock_on_hand,
            'price_delta' => $variant->price_delta,
        ];
        $variant->fill($validated);
        $variant->save();

        $this->audit->log($variant, 'variant.updated', $before, [
            'stock_on_hand' => $variant->stock_on_hand,
            'price_delta' => $variant->price_delta,
        ]);

        return response()->json(['data' => $variant->fresh()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'description' => $product->description,
            'class' => $product->class->value,
            'base_cost' => $product->base_cost,
            'currency' => $product->currency,
            'dimensions' => $product->dimensions,
            'weight' => $product->weight,
            'print_method' => $product->print_method?->value,
            'stock_mode' => $product->stock_mode?->value,
            'category' => $product->category,
            'image_url' => $product->image_url,
            'is_printable' => (bool) $product->is_printable,
            'publish_state' => $product->publish_state->value,
            // Superadmin-only compliance tier: standard | extended | high_risk.
            // Null licence (e.g. CORE blanks) has no risk → standard.
            'license_tier' => $product->license?->tier() ?? 'standard',
            'archived' => $product->trashed(),
            'variants' => $product->relationLoaded('variants') ? $product->variants : null,
            'sold_count' => (int) ($product->sold_count ?? 0),
            'stock_total' => (int) ($product->variants_sum_stock_on_hand ?? 0),
        ];
    }
}
