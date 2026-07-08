<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ProductClass;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
     * Stream the 3D model file for the interactive viewer. Published
     * MODEL_3D items only; CC0/CC-BY permits redistribution and CC-BY
     * credit is displayed alongside the viewer. Cache-friendly (immutable
     * per slug - slugs are stable).
     */
    public function model(string $key): StreamedResponse|JsonResponse
    {
        $product = Product::query()->where('slug', $key)->first()
            ?? (ctype_digit($key) ? Product::find((int) $key) : null);

        $ref = (string) ($product?->model_file_ref ?? '');

        if (
            $product === null
            || ! $product->publish_state->isPublic()
            || $product->class !== ProductClass::Model3d
            || $ref === ''
            || str_starts_with($ref, 'http')
            || ! Storage::disk('local')->exists($ref)
        ) {
            return response()->json(['message' => 'Model not available.'], 404);
        }

        return Storage::disk('local')->response($ref, basename($ref), [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
