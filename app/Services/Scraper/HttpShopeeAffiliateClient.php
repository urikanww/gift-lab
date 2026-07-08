<?php

declare(strict_types=1);

namespace App\Services\Scraper;

use App\Services\Scraper\Contracts\ScraperClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Live Shopee Affiliate Open API client (GraphQL). This is a permitted,
 * ToS-clean product feed - the affiliate program exists precisely to let
 * partners display Shopee listings - not HTML scraping, and never a checkout
 * bot (spec 7 stands: procurement remains a human purchase).
 *
 * The feed provides name/price/image/link only. Dimensions, weight and
 * printability are physical judgements the feed cannot make, so ingested
 * items intentionally land in the admin completeness gate for staff to
 * complete before they can publish.
 *
 * Auth: SHA256 app signature - Authorization: SHA256 Credential={appId},
 * Timestamp={ts}, Signature=sha256(appId + ts + body + secret).
 *
 * sourceProductId format: "{itemId}" or "{shopId}_{itemId}" (the pair appears
 * in Shopee URLs as i.{shopId}.{itemId}).
 */
final class HttpShopeeAffiliateClient implements ScraperClient
{
    public function fetch(string $sourceProductId): ?ScrapedProductData
    {
        $itemId = str_contains($sourceProductId, '_')
            ? (int) explode('_', $sourceProductId, 2)[1]
            : (int) $sourceProductId;

        if ($itemId <= 0) {
            return null;
        }

        $query = <<<'GQL'
        query ($itemId: Int64!) {
          productOfferV2(itemId: $itemId) {
            nodes {
              itemId
              productName
              priceMin
              imageUrl
              productLink
            }
          }
        }
        GQL;

        $result = $this->request($query, ['itemId' => $itemId]);

        if ($result === null) {
            return null;
        }

        $node = $result['productOfferV2']['nodes'][0] ?? null;

        if ($node === null) {
            // Listing removed/expired upstream - signal dead source so the
            // resync flips the product to CANNOT_PUBLISH instead of erroring.
            return new ScrapedProductData(
                sourceProductId: $sourceProductId,
                sourceUrl: '',
                name: null,
                price: null,
                dimensions: null,
                weight: null,
                stockEstimate: null,
                imageUrl: null,
                printable: false,
                sourceDead: true,
            );
        }

        return $this->toData($node, $sourceProductId);
    }

    /**
     * Keyword search for the discovery command. Each hit is a partial listing
     * destined for the completeness gate.
     *
     * @return array<int, ScrapedProductData>
     */
    public function search(string $keyword, int $limit = 20): array
    {
        $query = <<<'GQL'
        query ($keyword: String!, $limit: Int!) {
          productOfferV2(keyword: $keyword, limit: $limit) {
            nodes {
              itemId
              shopId
              productName
              priceMin
              imageUrl
              productLink
            }
          }
        }
        GQL;

        $result = $this->request($query, ['keyword' => $keyword, 'limit' => $limit]);

        $nodes = $result['productOfferV2']['nodes'] ?? [];

        return collect($nodes)
            ->filter(fn ($node): bool => is_array($node) && ! empty($node['itemId']))
            ->map(function (array $node): ScrapedProductData {
                $id = isset($node['shopId'])
                    ? "{$node['shopId']}_{$node['itemId']}"
                    : (string) $node['itemId'];

                return $this->toData($node, $id);
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function toData(array $node, string $sourceProductId): ScrapedProductData
    {
        return new ScrapedProductData(
            sourceProductId: $sourceProductId,
            sourceUrl: (string) ($node['productLink'] ?? ''),
            name: isset($node['productName']) ? (string) $node['productName'] : null,
            price: isset($node['priceMin']) ? (float) $node['priceMin'] : null,
            // Physical attributes are staff judgements, not feed data.
            dimensions: null,
            weight: null,
            stockEstimate: null,
            imageUrl: isset($node['imageUrl']) ? (string) $node['imageUrl'] : null,
            printable: false,
        );
    }

    /**
     * Signed GraphQL request. Null on any failure - a feed outage degrades
     * only the scraped catalogue, never the core flow (spec principle 3).
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>|null
     */
    private function request(string $query, array $variables): ?array
    {
        $appId = (string) config('services.shopee_affiliate.app_id');
        $secret = (string) config('services.shopee_affiliate.secret');
        $baseUrl = (string) config('services.shopee_affiliate.base_url');

        if ($appId === '' || $secret === '') {
            Log::warning('Shopee affiliate credentials are not configured.');

            return null;
        }

        $body = json_encode(['query' => $query, 'variables' => $variables], JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->timestamp;
        $signature = hash('sha256', $appId.$timestamp.$body.$secret);

        try {
            $response = Http::withHeaders([
                'Authorization' => "SHA256 Credential={$appId}, Timestamp={$timestamp}, Signature={$signature}",
                'Content-Type' => 'application/json',
            ])
                ->connectTimeout(5)
                ->timeout(20)
                ->retry(2, 500, throw: false)
                ->withBody((string) $body, 'application/json')
                ->post($baseUrl);
        } catch (Throwable $e) {
            Log::warning('Shopee affiliate request failed (transport error).', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful() || $response->json('errors') !== null) {
            Log::warning('Shopee affiliate request failed.', [
                'status' => $response->status(),
                'errors' => $response->json('errors'),
            ]);

            return null;
        }

        return (array) $response->json('data');
    }
}
