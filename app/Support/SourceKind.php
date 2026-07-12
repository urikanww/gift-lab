<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Normalise a product's source_url host into a small, filterable label. Persisted
 * on products.source_kind so the catalogue gate can filter/display by provenance
 * without re-parsing URLs at query time.
 */
final class SourceKind
{
    public const ALL = ['marketplace', 'local', 'makerworld', 'thingiverse', 'cults3d', 'manual'];

    private const MARKETPLACE = ['shopee.', 'lazada.', 'amazon.', 'aliexpress.', 'taobao.', '1688.', 'qoo10.'];

    public static function fromUrl(?string $url): string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return 'manual';
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return 'manual';
        }

        if (str_contains($host, 'makerworld')) {
            return 'makerworld';
        }
        if (str_contains($host, 'thingiverse')) {
            return 'thingiverse';
        }
        if (str_contains($host, 'cults3d')) {
            return 'cults3d';
        }
        foreach (self::MARKETPLACE as $needle) {
            if (str_contains($host, $needle)) {
                return 'marketplace';
            }
        }

        return 'local';
    }
}
