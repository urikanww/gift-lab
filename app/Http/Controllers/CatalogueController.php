<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Public, no-account catalogue (spec 6.1). Only PUBLISHED products are exposed;
 * scraped stock/price shown here is indicative and never authoritative.
 * Read-only and cacheable — no realtime, so no Reverb here.
 */
class CatalogueController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $products = Product::query()
            ->published()
            ->when(
                $request->filled('class'),
                fn ($q) => $q->where('class', $request->string('class')->toString())
            )
            ->when(
                $request->filled('q'),
                fn ($q) => $q->where('name', 'like', '%'.$request->string('q')->toString().'%')
            )
            ->with('variants')
            ->orderBy('name')
            ->paginate(24);

        return ProductResource::collection($products);
    }

    public function show(Product $product): ProductResource|JsonResponse
    {
        if (! $product->publish_state->isPublic()) {
            return response()->json(['message' => 'Product not available.'], 404);
        }

        return new ProductResource($product->load('variants'));
    }
}
