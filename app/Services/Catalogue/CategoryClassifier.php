<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

use App\Enums\ProductClass;

/**
 * Maps a product name to the public marketplace category (how buyers shop),
 * decoupled from the internal print-class taxonomy (how items are produced).
 * First keyword match in declaration order wins; word-boundary matching so
 * e.g. 'tee' never fires inside 'Steel'.
 */
class CategoryClassifier
{
    /** Stable public category slugs, in display order. */
    public const CATEGORIES = [
        'drinkware', 'bags', 'stationery', 'apparel', 'tech', 'home', 'accessories', 'toys',
    ];

    /**
     * Curated cross-category gifting pairs: what naturally goes with what. Powers
     * the PDP "You might also like" rail so a mug surfaces coasters/keychains, not
     * random stock. Listed in pairing strength (earlier = stronger).
     */
    public const COMPLEMENTS = [
        'drinkware' => ['home', 'accessories'],
        'bags' => ['apparel', 'accessories'],
        'stationery' => ['tech', 'accessories'],
        'apparel' => ['bags', 'accessories'],
        'tech' => ['stationery', 'accessories'],
        'home' => ['drinkware', 'accessories'],
        'accessories' => ['bags', 'drinkware'],
        'toys' => ['accessories', 'home'],
    ];

    private const KEYWORDS = [
        'drinkware' => ['mug', 'tumbler', 'bottle', 'cup', 'flask', 'thermos', 'stein', 'glass'],
        'bags' => ['tote', 'bag', 'pouch', 'backpack', 'sling', 'drawstring'],
        'stationery' => ['notebook', 'pen', 'pencil', 'journal', 'planner', 'notepad', 'bookmark', 'ruler', 'eraser'],
        'apparel' => ['t-shirt', 'tee', 'shirt', 'hoodie', 'cap', 'hat', 'sock', 'apron', 'jersey'],
        'tech' => ['phone', 'grip', 'charger', 'cable', 'usb', 'mouse', 'stand', 'holder', 'earbud', 'headphone', 'speaker', 'laptop'],
        'home' => ['coaster', 'candle', 'vase', 'planter', 'frame', 'organiser', 'organizer', 'tray', 'clock', 'ornament', 'magnet'],
        'accessories' => ['keychain', 'keyring', 'key ring', 'pin', 'badge', 'lanyard', 'strap', 'charm', 'carabiner', 'wristband'],
        'toys' => ['figurine', 'figure', 'toy', 'dragon', 'articulated', 'puzzle', 'dice', 'miniature', 'fidget'],
    ];

    public function classify(string $name, ProductClass $class): string
    {
        foreach (self::KEYWORDS as $category => $keywords) {
            foreach ($keywords as $keyword) {
                // Tolerate simple plurals ("Mugs", "Pins") - except 'glass',
                // where +s would wrongly capture eyewear ("Reading Glasses").
                $plural = $keyword === 'glass' ? '' : 's?';

                if (preg_match('/\b'.preg_quote($keyword, '/').$plural.'\b/i', $name) === 1) {
                    return $category;
                }
            }
        }

        return $class === ProductClass::Model3d ? 'toys' : 'accessories';
    }
}
