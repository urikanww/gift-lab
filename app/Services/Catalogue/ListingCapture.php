<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

use App\Services\Scraper\ScrapedProductData;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Capture-on-browse: fetch ONE product page (staff-initiated) and extract public
 * fields into a ScrapedProductData draft. Prefers JSON-LD Product, then Open
 * Graph, then <title>. Standard HTML/OG pages (most local suppliers) extract
 * cleanly; JS-heavy anti-bot marketplaces may only yield the URL-derived id +
 * whatever OG the shell serves — staff completes the rest in the gate.
 */
final class ListingCapture
{
    public function capture(string $url): ?ScrapedProductData
    {
        try {
            $res = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                    .'(KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml',
            ])->connectTimeout(5)->timeout(20)->get($url);
        } catch (Throwable) {
            return null;
        }

        if (! $res->successful()) {
            return null;
        }

        $html = $res->body();
        $ld = $this->fromJsonLd($html);
        $name = $ld['name'] ?? $this->ogContent($html, 'og:title') ?? $this->titleTag($html);
        $price = $ld['price'] ?? $this->priceMeta($html);
        $image = $ld['image'] ?? $this->ogContent($html, 'og:image');

        return new ScrapedProductData(
            sourceProductId: $this->deriveId($url),
            sourceUrl: $url,
            name: $name !== null ? trim($name) : null,
            price: $price,
            dimensions: null,
            weight: null,
            stockEstimate: null,
            imageUrl: $image,
            printable: false,
        );
    }

    /** @return array{name?:string,price?:float,image?:string} */
    private function fromJsonLd(string $html): array
    {
        if (! preg_match_all('#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $m)) {
            return [];
        }
        foreach ($m[1] as $block) {
            $json = json_decode(trim($block), true);
            if (! is_array($json)) {
                continue;
            }
            foreach ($this->flattenLd($json) as $node) {
                if (! is_array($node)) {
                    continue;
                }
                $type = $node['@type'] ?? null;
                $isProduct = $type === 'Product' || (is_array($type) && in_array('Product', $type, true));
                if (! $isProduct) {
                    continue;
                }
                $offer = $node['offers'] ?? [];
                if (isset($offer[0])) {
                    $offer = $offer[0];
                }
                $img = $node['image'] ?? null;
                if (is_array($img)) {
                    $img = $img[0] ?? null;
                }

                return array_filter([
                    'name' => isset($node['name']) ? (string) $node['name'] : null,
                    'price' => isset($offer['price']) && is_numeric($offer['price']) ? (float) $offer['price'] : null,
                    'image' => $img !== null ? (string) $img : null,
                ], fn ($v) => $v !== null);
            }
        }

        return [];
    }

    /**
     * @param  array<mixed>  $json
     * @return array<int, array<string, mixed>>
     */
    private function flattenLd(array $json): array
    {
        if (isset($json['@graph']) && is_array($json['@graph'])) {
            return $json['@graph'];
        }
        if (array_is_list($json)) {
            return $json;
        }

        return [$json];
    }

    private function ogContent(string $html, string $property): ?string
    {
        // Match any <meta ...> tag carrying this property, in either attribute
        // order, then pull its content= value regardless of position. Supplier
        // and marketplace HTML emits both `property before content` and the
        // reversed `content before property`.
        if (! preg_match_all('#<meta\b[^>]*>#i', $html, $tags)) {
            return null;
        }
        $quoted = preg_quote($property, '#');
        foreach ($tags[0] as $tag) {
            if (! preg_match('#\bproperty=["\']'.$quoted.'["\']#i', $tag)) {
                continue;
            }
            if (preg_match('#\bcontent=["\'](.*?)["\']#i', $tag, $m)) {
                return html_entity_decode($m[1]);
            }
        }

        return null;
    }

    private function priceMeta(string $html): ?float
    {
        foreach (['product:price:amount', 'og:price:amount'] as $prop) {
            $v = $this->ogContent($html, $prop);
            if ($v !== null && is_numeric($v)) {
                return (float) $v;
            }
        }

        return null;
    }

    private function titleTag(string $html): ?string
    {
        if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
            return html_entity_decode(trim($m[1]));
        }

        return null;
    }

    /**
     * Shopee/Lazada-style "/{shopId}/{itemId}" → "{shopId}_{itemId}" (matches the
     * affiliate client's id format so the same item dedupes). Otherwise a stable
     * host+path slug.
     */
    private function deriveId(string $url): string
    {
        if (preg_match('#/(\d{5,})/(\d{5,})#', $url, $m)) {
            return "{$m[1]}_{$m[2]}";
        }
        if (preg_match('#i\.(\d+)\.(\d+)#', $url, $m)) {
            return "{$m[1]}_{$m[2]}";
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        $path = (string) parse_url($url, PHP_URL_PATH);

        return trim($host.$path, '/') ?: $url;
    }
}
