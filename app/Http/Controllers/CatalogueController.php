<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ProductClass;
use App\Http\Resources\ProductResource;
use App\Models\LineItem;
use App\Models\Product;
use App\Services\Catalogue\CategoryClassifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Public, no-account catalogue (spec 6.1). Only PUBLISHED products are exposed;
 * scraped stock/price shown here is indicative and never authoritative.
 * Read-only and cacheable - no realtime, so no Reverb here.
 */
class CatalogueController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        // `sort=price_*` orders by base_cost: the public from_price is a
        // monotonic margin over base_cost, so relative order is preserved
        // without leaking the internal cost itself.
        $sort = $request->string('sort')->toString();

        $query = Product::query()
            ->published()
            ->when(
                $request->filled('class'),
                fn ($q) => $q->where('class', $request->string('class')->toString())
            )
            ->when(
                $request->filled('category'),
                fn ($q) => $q->where('category', $request->string('category')->toString())
            )
            ->when(
                $request->filled('q'),
                fn ($q) => $q->where('name', 'like', '%'.$request->string('q')->toString().'%')
            )
            ->with('variants');

        match ($sort) {
            'price_asc' => $query->orderBy('base_cost')->orderBy('name'),
            'price_desc' => $query->orderByDesc('base_cost')->orderBy('name'),
            'newest' => $query->orderByDesc('created_at')->orderBy('name'),
            default => $query->orderBy('name'),
        };

        return ProductResource::collection(
            $query->paginate(24)->appends($request->query())
        );
    }

    /**
     * Resolve by slug (public, user-friendly URLs - no id enumeration);
     * numeric ids still resolve so pre-slug links and stored cart lines
     * keep working.
     */
    public function show(string $key): ProductResource|JsonResponse
    {
        $product = Product::query()->where('slug', $key)->first();

        if ($product === null && ctype_digit($key)) {
            $product = Product::find((int) $key);
        }

        if ($product === null || ! $product->publish_state->isPublic()) {
            return response()->json(['message' => 'Product not available.'], 404);
        }

        return new ProductResource($product->load('variants'));
    }

    /**
     * Relevance-ranked "You might also like" for the PDP:
     *   0. Frequently bought together - products that co-appear in real quotes
     *      (a learned signal; empty until there's order history)
     *   1. Same category      - true "similar" (a mug → more mugs)
     *   2. Complementary cats  - curated pairs (the coaster/keychain that pairs)
     *   3. Newest fill         - keeps the rail full when the above are thin
     * Server-side so complements are found across the whole catalogue, not just
     * the client's loaded page. Best-effort: unknown/unpublished key → empty rail.
     */
    public function related(string $key): AnonymousResourceCollection
    {
        $product = Product::query()->where('slug', $key)->first()
            ?? (ctype_digit($key) ? Product::find((int) $key) : null);

        if ($product === null || ! $product->publish_state->isPublic()) {
            return ProductResource::collection(collect());
        }

        $limit = 10;
        $category = (string) ($product->category ?? '');
        $complements = CategoryClassifier::COMPLEMENTS[$category] ?? [];

        $base = fn () => Product::query()
            ->published()
            ->whereKeyNot($product->id)
            ->with('variants');

        $picked = collect();
        // Ids to skip on each subsequent tier (already picked + the product itself).
        $exclude = fn (): array => $picked->pluck('id')->push($product->id)->all();

        // Tier 0: frequently bought together (data signal; strongest when present).
        $picked = $picked->concat($this->coOrderedProducts($product, $limit));

        // Tier 1: same category.
        if ($category !== '' && $picked->count() < $limit) {
            $picked = $picked->concat(
                $base()->where('category', $category)->whereNotIn('id', $exclude())->orderBy('name')->limit($limit)->get()
            );
        }

        // Tier 2: complementary categories, ordered by pairing strength (map order).
        if ($complements !== [] && $picked->count() < $limit) {
            $picked = $picked->concat(
                $base()->whereIn('category', $complements)->whereNotIn('id', $exclude())->orderBy('name')->limit($limit)->get()
                    ->sortBy(fn (Product $p) => array_search($p->category, $complements, true))
                    ->values()
            );
        }

        // Tier 3: newest fill to keep the rail full.
        if ($picked->count() < $limit) {
            $picked = $picked->concat(
                $base()->whereNotIn('id', $exclude())->orderByDesc('created_at')->limit($limit - $picked->count())->get()
            );
        }

        return ProductResource::collection($picked->take($limit)->values());
    }

    /**
     * Products frequently bought together with the given one: other published
     * products that co-appear in the same quotes, ranked by how many distinct
     * quotes they share. A learned "also bought" signal that sharpens as orders
     * accrue; returns empty when the product has no order history yet.
     */
    private function coOrderedProducts(Product $product, int $limit): Collection
    {
        $quoteIds = LineItem::query()
            ->where('product_id', $product->id)
            ->distinct()
            ->pluck('quote_id');

        if ($quoteIds->isEmpty()) {
            return collect();
        }

        $counts = LineItem::query()
            ->whereIn('quote_id', $quoteIds)
            ->where('product_id', '!=', $product->id)
            ->selectRaw('product_id, COUNT(DISTINCT quote_id) as co_count')
            ->groupBy('product_id')
            ->orderByDesc('co_count')
            ->limit($limit)
            ->pluck('co_count', 'product_id');

        if ($counts->isEmpty()) {
            return collect();
        }

        // Keep only publishable co-orders, preserving the co-occurrence ranking.
        return Product::query()
            ->published()
            ->whereKeyNot($product->id)
            ->whereIn('id', $counts->keys())
            ->with('variants')
            ->get()
            ->sortByDesc(fn (Product $p): int => (int) ($counts[$p->id] ?? 0))
            ->values();
    }

    /**
     * Stream the 3D model file for the interactive viewer. Published
     * MODEL_3D items only; CC0/CC-BY permits redistribution and CC-BY
     * credit is displayed alongside the viewer. Cache-friendly (immutable
     * per slug - slugs are stable).
     */
    public function model(Request $request, string $key): SymfonyResponse
    {
        $product = Product::query()->where('slug', $key)->first()
            ?? (ctype_digit($key) ? Product::find((int) $key) : null);

        $ref = (string) ($product?->model_file_ref ?? '');
        $disk = (string) config('model3d.disk', 'local');

        if (
            $product === null
            || ! $product->publish_state->isPublic()
            || $product->class !== ProductClass::Model3d
            || $ref === ''
            || str_starts_with($ref, 'http')
            || ! Storage::disk($disk)->exists($ref)
        ) {
            return response()->json(['message' => 'Model not available.'], 404);
        }

        // Validate on a content signature (lastModified + size), NOT a long fixed
        // max-age: the model URL is stable (models3d/{source}-{id}.stl) but the
        // file behind it is replaced when a model is re-pulled (resync --force).
        // A fixed 24h cache would keep serving the OLD geometry - the classic
        // "preview shows the wrong model after a re-pull". must-revalidate makes
        // the browser send a cheap conditional GET and only refetch on change.
        // Read the signature from the disk (not filemtime/filesize) so this works
        // identically on local and S3 (spaces_models has no local path).
        $store = Storage::disk($disk);
        $size = (int) $store->size($ref);
        $etag = sprintf('"%d-%d"', (int) $store->lastModified($ref), $size);
        $cacheControl = 'public, max-age=0, must-revalidate';

        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return response('', 304, ['ETag' => $etag, 'Cache-Control' => $cacheControl]);
        }

        // Content-Length lets the frontend show a determinate download bar. The
        // local driver sets it via response(); S3 streaming may not, so pass the
        // known object size explicitly.
        return $store->response($ref, basename($ref), [
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => $size,
            'Cache-Control' => $cacheControl,
            'ETag' => $etag,
        ]);
    }
}
