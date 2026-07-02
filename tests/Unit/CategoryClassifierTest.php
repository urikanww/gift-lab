<?php

declare(strict_types=1);

use App\Enums\ProductClass;
use App\Services\Catalogue\CategoryClassifier;

it('classifies product names into marketplace categories', function (string $name, string $expected): void {
    expect((new CategoryClassifier())->classify($name, ProductClass::Core))->toBe($expected);
})->with([
    ['Ceramic Mug 11oz', 'drinkware'],
    // 'tee' must NOT match inside 'Stainless' / 'Steel' — word boundaries required.
    ['Stainless Tumbler 500ml', 'drinkware'],
    ['Glass Water Bottle 600ml', 'drinkware'],
    ['Canvas Tote Bag', 'bags'],
    ['A5 Hardcover Notebook', 'stationery'],
    ['Ballpoint Pen (Metal)', 'stationery'],
    ['Cotton T-Shirt', 'apparel'],
    ['Silicone Phone Grip', 'tech'],
    ['Bamboo Coaster', 'home'],
    ['Enamel Keychain', 'accessories'],
    ['Articulated Dragon Fidget', 'toys'],
    // Plural tolerance — ordinary catalogue names are often pluralized.
    ['Ceramic Mugs Set', 'drinkware'],
    ['Enamel Pins', 'accessories'],
    // 'glass' must NOT pluralize into eyewear.
    ['Reading Glasses', 'accessories'],
]);

it('falls back by product class when no keyword matches', function (): void {
    $classifier = new CategoryClassifier();

    expect($classifier->classify('Mystery Object', ProductClass::Model3d))->toBe('toys')
        ->and($classifier->classify('Mystery Object', ProductClass::Core))->toBe('accessories')
        ->and($classifier->classify('Mystery Object', ProductClass::ScrapedUv))->toBe('accessories');
});

it('keeps every keyword group inside the public CATEGORIES list', function (): void {
    $reflection = new ReflectionClassConstant(CategoryClassifier::class, 'KEYWORDS');

    expect(array_keys($reflection->getValue()))->toBe(CategoryClassifier::CATEGORIES);
});
