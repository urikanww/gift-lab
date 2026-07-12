<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\GiftIdeaFeature;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Public gift-ideas page feed: staff-curated affiliate products, IP-flagged rows
 * excluded. Only the affiliate offer_link + display fields are exposed - never
 * the plain product_link or internal ids. Cached; RefreshGiftIdeas busts it.
 */
final class GiftIdeasController extends Controller
{
    public const CACHE_KEY = 'gift_ideas.public';

    public function index(): JsonResponse
    {
        $data = Cache::remember(self::CACHE_KEY, now()->addHour(), function (): array {
            return GiftIdeaFeature::query()
                ->where('ip_flagged', false)
                ->orderBy('sort')
                ->orderByDesc('id')
                ->get()
                ->map(fn (GiftIdeaFeature $f): array => [
                    'name' => $f->name,
                    'image_url' => $f->image_url,
                    'offer_link' => $f->offer_link,
                    'price' => $f->price,
                    'currency' => $f->currency,
                    'shop_name' => $f->shop_name,
                ])
                ->all();
        });

        return response()->json(['data' => $data]);
    }
}
