<?php

declare(strict_types=1);

namespace App\Services\Scraper;

use App\Services\Scraper\Contracts\ScraperClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Live Lazada Open Platform affiliate client - the second permitted UV-blank
 * feed alongside Shopee. Same posture: product data only (name/price/image/
 * link), physical attributes stay staff judgements in the completeness gate,
 * and procurement remains a human purchase (spec 7).
 *
 * Auth: Lazada open-platform signature - sign = uppercase HMAC-SHA256 of
 * (apiPath + concatenated sorted key+value pairs) with the app secret.
 * The search API path is configurable (services.lazada_affiliate.search_path)
 * because Lazada scopes endpoints per affiliate program - confirm the path in
 * your program's API console.
 *
 * sourceProductId format: "lazada:{itemId}" (see CompositeScraperClient).
 */
final class HttpLazadaAffiliateClient implements ScraperClient
{
    public function fetch(string $sourceProductId): ?ScrapedProductData
    {
        $itemId = str_starts_with($sourceProductId, 'lazada:')
            ? substr($sourceProductId, 7)
            : $sourceProductId;

        if ($itemId === '') {
            return null;
        }

        $result = $this->request(
            (string) config('services.lazada_affiliate.item_path', '/marketing/product/detail'),
            ['itemId' => $itemId],
        );

        if ($result === null) {
            return null;
        }

        $node = $this->firstProduct($result);

        if ($node === null) {
            return new ScrapedProductData(
                sourceProductId: "lazada:{$itemId}",
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

        return $this->toData($node, "lazada:{$itemId}");
    }

    /**
     * Keyword search for the pull command.
     *
     * @return array<int, ScrapedProductData>
     */
    public function search(string $keyword, int $limit = 20): array
    {
        $result = $this->request(
            (string) config('services.lazada_affiliate.search_path', '/marketing/product/search'),
            ['keyword' => $keyword, 'pageSize' => $limit],
        );

        if ($result === null) {
            return [];
        }

        return collect($this->products($result))
            ->map(function (array $node): ?ScrapedProductData {
                $id = $node['product_id'] ?? $node['item_id'] ?? $node['itemId'] ?? null;

                return $id === null ? null : $this->toData($node, 'lazada:'.$id);
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function toData(array $node, string $sourceProductId): ScrapedProductData
    {
        $price = $node['app_price'] ?? $node['price'] ?? $node['sale_price'] ?? null;

        return new ScrapedProductData(
            sourceProductId: $sourceProductId,
            sourceUrl: (string) ($node['product_url'] ?? $node['url'] ?? ''),
            name: isset($node['product_name']) ? (string) $node['product_name'] : ($node['name'] ?? null),
            price: $price !== null ? (float) $price : null,
            dimensions: null,
            weight: null,
            stockEstimate: null,
            imageUrl: isset($node['image_url']) ? (string) $node['image_url'] : ($node['picture_url'] ?? null),
            printable: false,
        );
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<int, array<string, mixed>>
     */
    private function products(array $result): array
    {
        // Envelope varies by endpoint version; probe the known shapes.
        $list = $result['products'] ?? $result['data']['products'] ?? $result['result']['products'] ?? [];

        return array_values(array_filter((array) $list, 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>|null
     */
    private function firstProduct(array $result): ?array
    {
        return $this->products($result)[0] ?? null;
    }

    /**
     * Signed GET request per Lazada open-platform convention. Null on any
     * failure - a feed outage degrades only the scraped catalogue.
     *
     * @param  array<string, string|int>  $params
     * @return array<string, mixed>|null
     */
    private function request(string $apiPath, array $params): ?array
    {
        $appKey = (string) config('services.lazada_affiliate.app_key');
        $secret = (string) config('services.lazada_affiliate.secret');
        $baseUrl = (string) config('services.lazada_affiliate.base_url');

        if ($appKey === '' || $secret === '') {
            Log::warning('Lazada affiliate credentials are not configured.');

            return null;
        }

        $params = array_merge($params, [
            'app_key' => $appKey,
            'timestamp' => (string) (now()->getTimestampMs()),
            'sign_method' => 'sha256',
        ]);

        ksort($params);

        $canonical = $apiPath;
        foreach ($params as $key => $value) {
            $canonical .= $key.$value;
        }

        $params['sign'] = strtoupper(hash_hmac('sha256', $canonical, $secret));

        try {
            $response = Http::acceptJson()
                ->connectTimeout(5)
                ->timeout(20)
                ->retry(2, 500, throw: false)
                ->get($baseUrl.$apiPath, $params);
        } catch (Throwable $e) {
            Log::warning('Lazada affiliate request failed (transport error).', ['error' => $e->getMessage()]);

            return null;
        }

        $body = (array) $response->json();

        // Lazada signals errors in-body with a non-"0" code.
        if (! $response->successful() || (isset($body['code']) && (string) $body['code'] !== '0')) {
            Log::warning('Lazada affiliate request failed.', [
                'status' => $response->status(),
                'code' => $body['code'] ?? null,
                'message' => $body['message'] ?? null,
            ]);

            return null;
        }

        return $body;
    }
}
