<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ProductClass;
use App\Enums\PublishState;
use App\Models\Product;
use App\Models\Variant;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Staff CRUD over CORE products and their variants (audit E4): ops can add a
 * new blank with variants + stock, or fix a price, without touching seeders or
 * the DB. Scraped/3D items keep their own ingest + gate flows — this surface
 * manages the CORE class only.
 */
class AdminProductController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

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

        $paginator = Product::query()
            ->when($status === 'archived', fn ($q) => $q->onlyTrashed())
            ->when($status === 'all', fn ($q) => $q->withTrashed())
            ->when(
                in_array($class, ['CORE', 'SCRAPED_UV', 'MODEL_3D'], true),
                fn ($q) => $q->where('class', $class),
            )
            ->with('variants')
            ->orderBy('name')
            ->paginate($perPage);

        return response()->json([
            'data' => collect($paginator->items())->map(fn (Product $p): array => $this->serialize($p)),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
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
        $validated = $request->validate($rules);

        if (isset($validated['dimensions'])) {
            $validated['dimensions'] = $validated['dimensions'] + ['unit' => 'mm'];
        }

        $before = ['base_cost' => $product->base_cost, 'publish_state' => $product->publish_state->value];
        $product->fill($validated);
        $product->save();

        $this->audit->log($product, 'product.updated', $before, [
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

        if ($product->class !== ProductClass::Core) {
            return response()->json(['message' => 'Variants are managed for CORE products only.'], 422);
        }

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
        ];
    }
}
