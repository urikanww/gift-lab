<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pure helpers for a product's source_links list — the ranked buy links per UV
 * blank. Shape: [{label, url, kind: local|marketplace, price, currency,
 * last_checked}]. Marketplace hosts are known plain-URL storefronts; everything
 * else is treated as a local supplier.
 */
final class SourceLinks
{
    private const MARKETPLACE_HOSTS = ['shopee.', 'lazada.', 'amazon.', 'aliexpress.', 'taobao.', '1688.', 'qoo10.'];

    public static function guessKind(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        foreach (self::MARKETPLACE_HOSTS as $needle) {
            if (str_contains($host, $needle)) {
                return 'marketplace';
            }
        }

        return 'local';
    }

    /**
     * @param  array<string, mixed>  $link
     * @return array{label:string,url:string,kind:string,price:float|null,currency:string,last_checked:string|null}
     */
    public static function normalize(array $link): array
    {
        $url = (string) ($link['url'] ?? '');
        $host = (string) parse_url($url, PHP_URL_HOST);

        return [
            'label' => trim((string) ($link['label'] ?? '')) ?: ($host !== '' ? $host : 'Source'),
            'url' => $url,
            'kind' => in_array($link['kind'] ?? null, ['local', 'marketplace'], true)
                ? (string) $link['kind']
                : self::guessKind($url),
            'price' => isset($link['price']) && is_numeric($link['price']) ? (float) $link['price'] : null,
            'currency' => (string) ($link['currency'] ?? 'SGD'),
            'last_checked' => isset($link['last_checked']) ? (string) $link['last_checked'] : null,
        ];
    }

    /**
     * Add/merge a link into the list, deduped by url (last write wins).
     *
     * @param  array<int, array<string, mixed>>  $links
     * @param  array<string, mixed>  $link
     * @return array<int, array<string, mixed>>
     */
    public static function add(array $links, array $link): array
    {
        $normalized = self::normalize($link);
        $out = [];
        $replaced = false;
        foreach ($links as $existing) {
            if (($existing['url'] ?? null) === $normalized['url']) {
                $out[] = $normalized;
                $replaced = true;

                continue;
            }
            $out[] = self::normalize($existing);
        }
        if (! $replaced) {
            $out[] = $normalized;
        }

        return array_values($out);
    }

    /**
     * @param  array<int, array<string, mixed>>  $links
     */
    public static function primaryUrl(array $links): ?string
    {
        foreach ($links as $link) {
            if (($link['kind'] ?? null) === 'local' && ! empty($link['url'])) {
                return (string) $link['url'];
            }
        }

        return isset($links[0]['url']) && $links[0]['url'] !== '' ? (string) $links[0]['url'] : null;
    }
}
