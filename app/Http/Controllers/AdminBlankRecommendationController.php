<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\GiftIdeaFeature;
use App\Services\Catalogue\CandidateScreen;
use App\Services\Catalogue\ScrapedCatalogueService;
use App\Services\Scraper\HttpShopeeAffiliateClient;
use App\Services\Scraper\ScrapedProductData;
use App\Support\SourceLinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Staff blank recommender: keyword -> ranked Shopee affiliate candidates ->
 * "Add as blank" (into the gate) or "Feature publicly" (gift-ideas page).
 * Read-only against the affiliate API; adding reuses the scraped-UV ingest.
 */
final class AdminBlankRecommendationController extends Controller
{
    public function index(Request $request, HttpShopeeAffiliateClient $client, CandidateScreen $screen): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);
        $keyword = trim((string) $request->string('keyword'));
        if ($keyword === '') {
            return response()->json(['data' => []]);
        }
        $limit = max(1, min((int) $request->integer('limit', 20), 50));

        $candidates = collect($client->searchCandidates($keyword, $limit))
            ->sortByDesc('sales')
            ->map(fn ($c): array => [
                'source_product_id' => $c->sourceProductId,
                'name' => $c->name,
                'price' => $c->price,
                'currency' => $c->currency,
                'image_url' => $c->imageUrl,
                'product_link' => $c->productLink,
                'offer_link' => $c->offerLink,
                'sales' => $c->sales,
                'rating_star' => $c->ratingStar,
                'shop_name' => $c->shopName,
                'ip_flag' => $screen->ipFlag($c->name),
                'material_flag' => $screen->materialFlag($c->name),
            ])
            ->values();

        return response()->json(['data' => $candidates]);
    }

    public function add(Request $request, ScrapedCatalogueService $service): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);
        $v = $request->validate([
            'source_product_id' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:512'],
            'price' => ['nullable', 'numeric'],
            'image_url' => ['nullable', 'url', 'max:2048'],
            'product_link' => ['required', 'url', 'max:2048'],
        ]);

        $product = $service->ingest(new ScrapedProductData(
            sourceProductId: $v['source_product_id'],
            sourceUrl: $v['product_link'],
            name: $v['name'],
            price: isset($v['price']) ? (float) $v['price'] : null,
            dimensions: null, weight: null, stockEstimate: null,
            imageUrl: $v['image_url'] ?? null,
            printable: false,
        ));

        // Seed the PLAIN product link for buy-per-order procurement (not offerLink).
        $product->source_links = SourceLinks::add((array) $product->source_links, [
            'url' => $v['product_link'],
            'price' => $v['price'] ?? null,
            'currency' => 'SGD',
            'last_checked' => Carbon::now()->toIso8601String(),
        ]);
        $product->save();

        return response()->json(['data' => ['id' => $product->id, 'publish_state' => $product->publish_state->value]]);
    }

    public function feature(Request $request): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);
        $v = $request->validate([
            'source_product_id' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:512'],
            'price' => ['nullable', 'numeric'],
            'image_url' => ['nullable', 'url', 'max:2048'],
            'offer_link' => ['required', 'url', 'max:2048'],
            'product_link' => ['required', 'url', 'max:2048'],
            'shop_name' => ['nullable', 'string', 'max:255'],
            'ip_flagged' => ['nullable', 'boolean'],
        ]);

        $feature = GiftIdeaFeature::updateOrCreate(
            ['source_product_id' => $v['source_product_id']],
            [
                'name' => $v['name'], 'price' => $v['price'] ?? null,
                'image_url' => $v['image_url'] ?? null, 'offer_link' => $v['offer_link'],
                'product_link' => $v['product_link'], 'shop_name' => $v['shop_name'] ?? null,
                'ip_flagged' => (bool) ($v['ip_flagged'] ?? false),
                'created_by' => $request->user()->id,
            ],
        );

        return response()->json(['data' => ['id' => $feature->id]]);
    }

    public function unfeature(Request $request, GiftIdeaFeature $feature): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);
        $feature->delete();

        return response()->json(['data' => ['ok' => true]]);
    }
}
