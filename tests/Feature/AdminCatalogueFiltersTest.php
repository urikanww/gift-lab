<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    seedPricing();
    $this->staff = User::factory()->staffAdmin()->create();
    Sanctum::actingAs($this->staff);
});

it('filters by blocker reason', function (): void {
    Product::factory()->scrapedUv()->create(['name' => 'NoDims', 'publish_state' => 'CANNOT_PUBLISH', 'cannot_publish_reasons' => ['missing_dimensions']]);
    Product::factory()->scrapedUv()->create(['name' => 'NoPrice', 'publish_state' => 'CANNOT_PUBLISH', 'cannot_publish_reasons' => ['missing_price']]);

    $res = $this->getJson('/api/admin/catalogue?blocker=missing_dimensions')->assertOk();
    $names = collect($res->json('data'))->pluck('name');
    expect($names)->toContain('NoDims')->not->toContain('NoPrice');
});

it('filters by source kind and returns source_kind in the payload', function (): void {
    // source_kind is derived from the primary buy link (source_links -> source_url
    // -> source_kind, per the Product saving hooks and SourceKindTest), so the
    // buy link must carry the host we are filtering on - setting source_url alone
    // is overwritten by the factory's default source_links.
    Product::factory()->scrapedUv()->create(['name' => 'Shp', 'publish_state' => 'CANNOT_PUBLISH', 'source_url' => 'https://shopee.sg/product/1/2', 'source_links' => [['label' => 'S', 'url' => 'https://shopee.sg/product/1/2', 'kind' => 'marketplace', 'price' => 9.9, 'currency' => 'SGD', 'last_checked' => null]]]);
    Product::factory()->scrapedUv()->create(['name' => 'Loc', 'publish_state' => 'CANNOT_PUBLISH', 'source_url' => 'https://blankco.sg/mug', 'source_links' => [['label' => 'L', 'url' => 'https://blankco.sg/mug', 'kind' => 'local', 'price' => 9.9, 'currency' => 'SGD', 'last_checked' => null]]]);

    $res = $this->getJson('/api/admin/catalogue?source=local')->assertOk();
    $rows = collect($res->json('data'));
    expect($rows->pluck('name'))->toContain('Loc')->not->toContain('Shp')
        ->and($rows->firstWhere('name', 'Loc')['source_kind'])->toBe('local');
});

it('filters by print method', function (): void {
    Product::factory()->scrapedUv()->create(['name' => 'UvOne', 'publish_state' => 'CANNOT_PUBLISH', 'print_method' => 'UV']);
    Product::factory()->model3d()->create(['name' => 'FdmOne', 'publish_state' => 'CANNOT_PUBLISH', 'print_method' => 'FDM']);

    $res = $this->getJson('/api/admin/catalogue?print_method=FDM')->assertOk();
    expect(collect($res->json('data'))->pluck('name'))->toContain('FdmOne')->not->toContain('UvOne');
});

it('filters missing buy link (SCRAPED_UV with no source_links)', function (): void {
    Product::factory()->scrapedUv()->create(['name' => 'HasLink', 'publish_state' => 'CANNOT_PUBLISH', 'source_links' => [['label' => 'S', 'url' => 'https://x.sg/1', 'kind' => 'local', 'price' => 1.0, 'currency' => 'SGD', 'last_checked' => null]]]);
    Product::factory()->scrapedUv()->create(['name' => 'NoLink', 'publish_state' => 'CANNOT_PUBLISH', 'source_links' => []]);

    $res = $this->getJson('/api/admin/catalogue?missing_link=1')->assertOk();
    expect(collect($res->json('data'))->pluck('name'))->toContain('NoLink')->not->toContain('HasLink');
});

it('applies filters to the summary counts', function (): void {
    Product::factory()->scrapedUv()->create(['publish_state' => 'CANNOT_PUBLISH', 'print_method' => 'UV']);
    Product::factory()->model3d()->create(['publish_state' => 'CANNOT_PUBLISH', 'print_method' => 'FDM']);

    $res = $this->getJson('/api/admin/catalogue?print_method=UV')->assertOk();
    expect($res->json('counts.total'))->toBe(1);
});
