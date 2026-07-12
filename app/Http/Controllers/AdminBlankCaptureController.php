<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Catalogue\ListingCapture;
use App\Services\Catalogue\ScrapedCatalogueService;
use App\Support\SourceLinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Capture-on-browse: staff paste any product URL (Shopee/Lazada/local supplier)
 * and it becomes a draft SCRAPED_UV blank in the completeness gate, seeded with
 * that URL as its first source link. Staff then complete dimensions/weight/print
 * details and add alternate buy links before publishing.
 */
final class AdminBlankCaptureController extends Controller
{
    public function store(
        Request $request,
        ListingCapture $capture,
        ScrapedCatalogueService $service,
    ): JsonResponse {
        $validated = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
        ]);

        $data = $capture->capture($validated['url']);
        if ($data === null) {
            return response()->json(['message' => 'Could not read that page. Try again or add the blank manually.'], 502);
        }

        $product = $service->ingest($data);

        // Seed the captured URL as the first buy link (idempotent on re-capture).
        $product->source_links = SourceLinks::add((array) $product->source_links, [
            'url' => $data->sourceUrl,
            'price' => $data->price,
            'currency' => 'SGD',
            'last_checked' => Carbon::now()->toIso8601String(),
        ]);
        $product->save();

        return response()->json(['data' => [
            'id' => $product->id,
            'name' => $product->name,
            'publish_state' => $product->publish_state->value,
            'image_url' => $product->image_url,
            'source_url' => $product->source_url,
            'source_links' => $product->source_links,
        ]]);
    }
}
