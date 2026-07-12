<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

/**
 * Advisory screening for recommender candidates. Flags are shown to staff (never
 * auto-hidden in the recommender) but IP-flagged items are excluded from the
 * public gift-ideas page. Keyword lists are deliberately small + high-precision.
 */
final class CandidateScreen
{
    /** @var array<int, string> */
    private const BRANDS = [
        'disney', 'sanrio', 'hello kitty', 'pokemon', 'pokémon', 'marvel', 'dc comics',
        'nintendo', 'studio ghibli', 'bt21', 'bts', 'harry potter', 'star wars',
        'mofusand', 'kuromi', 'my melody', 'chiikawa', 'labubu',
    ];

    /** @var array<string, array<int, string>> flag => keywords */
    private const MATERIALS = [
        'fabric' => ['cotton', 'canvas', 'tote bag', 'linen', 'polyester', 'nylon', 't-shirt', 'apron'],
        'plush' => ['plush', 'teddy', 'stuffed'],
    ];

    public function ipFlag(string $name): ?string
    {
        $n = strtolower($name);
        foreach (self::BRANDS as $brand) {
            if (str_contains($n, $brand)) {
                // Normalise a couple of aliases to a single label.
                return match ($brand) {
                    'hello kitty', 'kuromi', 'my melody' => 'sanrio',
                    'pokémon' => 'pokemon',
                    default => $brand,
                };
            }
        }

        return null;
    }

    public function materialFlag(string $name): ?string
    {
        $n = strtolower($name);
        foreach (self::MATERIALS as $flag => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($n, $kw)) {
                    return $flag;
                }
            }
        }

        return null;
    }
}
