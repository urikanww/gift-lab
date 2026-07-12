<?php

declare(strict_types=1);

use App\Support\SourceKind;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

it('classifies known marketplaces', function (): void {
    expect(SourceKind::fromUrl('https://shopee.sg/product/1/2'))->toBe('marketplace');
    expect(SourceKind::fromUrl('https://www.lazada.sg/products/x.html'))->toBe('marketplace');
});

it('classifies 3D model sources', function (): void {
    expect(SourceKind::fromUrl('https://makerworld.com/en/models/3015782'))->toBe('makerworld');
    expect(SourceKind::fromUrl('https://www.thingiverse.com/thing:123'))->toBe('thingiverse');
    expect(SourceKind::fromUrl('https://cults3d.com/en/x'))->toBe('cults3d');
});

it('classifies empty as manual and anything else as local', function (): void {
    expect(SourceKind::fromUrl(null))->toBe('manual');
    expect(SourceKind::fromUrl(''))->toBe('manual');
    expect(SourceKind::fromUrl('https://blankco.sg/mug'))->toBe('local');
});

it('syncs source_kind on the product when saved', function (): void {
    $p = \App\Models\Product::factory()->scrapedUv()->create([
        'source_url' => 'https://shopee.sg/product/1/2',
        'source_links' => [['label' => 'S', 'url' => 'https://shopee.sg/product/1/2', 'kind' => 'marketplace', 'price' => 9.9, 'currency' => 'SGD', 'last_checked' => null]],
    ]);
    expect($p->fresh()->source_kind)->toBe('marketplace');
});
