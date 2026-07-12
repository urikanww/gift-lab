<?php

declare(strict_types=1);

use App\Http\Controllers\GiftIdeasController;
use App\Models\GiftIdeaFeature;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    seedPricing();
    $this->staff = User::factory()->staffAdmin()->create();
    config([
        'services.shopee_affiliate.app_id' => 'test-app',
        'services.shopee_affiliate.secret' => 'test-secret',
        'services.shopee_affiliate.base_url' => 'https://aff.test/graphql',
    ]);
});

function fakeCandidates(): void
{
    Http::fake(['aff.test/*' => Http::response(['data' => ['productOfferV2' => ['nodes' => [
        ['itemId' => 2, 'shopId' => 1, 'productName' => 'Disney Ceramic Mug', 'priceMin' => '20.00', 'imageUrl' => 'https://i/1', 'productLink' => 'https://shopee.sg/product/1/2', 'offerLink' => 'https://s.shopee.sg/aa', 'sales' => 10, 'ratingStar' => '4.5', 'shopName' => 'S1'],
        ['itemId' => 4, 'shopId' => 3, 'productName' => 'Plain Ceramic Mug 440ml', 'priceMin' => '9.90', 'imageUrl' => 'https://i/2', 'productLink' => 'https://shopee.sg/product/3/4', 'offerLink' => 'https://s.shopee.sg/bb', 'sales' => 300, 'ratingStar' => '4.9', 'shopName' => 'S2'],
    ]]]], 200)]);
}

it('returns ranked candidates with IP/material flags (staff only)', function (): void {
    fakeCandidates();
    Sanctum::actingAs($this->staff);
    $res = $this->getJson('/api/admin/blank-recommendations?keyword=mug&limit=10')->assertOk();

    $data = $res->json('data');
    expect($data[0]['source_product_id'])->toBe('3_4')
        ->and($data[0]['ip_flag'])->toBeNull()
        ->and(collect($data)->firstWhere('source_product_id', '1_2')['ip_flag'])->toBe('disney');
});

it('reports has_more true on a full page and forwards the page number', function (): void {
    // A full page (count === limit) signals Shopee likely has more.
    Http::fake(['aff.test/*' => Http::response(['data' => ['productOfferV2' => ['nodes' => [
        ['itemId' => 2, 'shopId' => 1, 'productName' => 'Mug A', 'priceMin' => '5.00', 'imageUrl' => 'https://i/a', 'productLink' => 'https://shopee.sg/product/1/2', 'offerLink' => 'https://s.shopee.sg/a', 'sales' => 5, 'ratingStar' => '4.0', 'shopName' => 'S'],
        ['itemId' => 4, 'shopId' => 3, 'productName' => 'Mug B', 'priceMin' => '6.00', 'imageUrl' => 'https://i/b', 'productLink' => 'https://shopee.sg/product/3/4', 'offerLink' => 'https://s.shopee.sg/b', 'sales' => 9, 'ratingStar' => '4.1', 'shopName' => 'S'],
    ]]]], 200)]);
    Sanctum::actingAs($this->staff);

    $res = $this->getJson('/api/admin/blank-recommendations?keyword=mug&limit=2&page=3')->assertOk();

    expect($res->json('has_more'))->toBeTrue()
        ->and($res->json('page'))->toBe(3);
});

it('reports has_more false on a short (final) page', function (): void {
    fakeCandidates(); // 2 nodes
    Sanctum::actingAs($this->staff);

    $res = $this->getJson('/api/admin/blank-recommendations?keyword=mug&limit=10')->assertOk();

    expect($res->json('has_more'))->toBeFalse()
        ->and($res->json('page'))->toBe(1);
});

it('forbids non-staff on search', function (): void {
    $buyer = User::factory()->create(['role' => 'buyer']);
    Sanctum::actingAs($buyer);
    $this->getJson('/api/admin/blank-recommendations?keyword=mug')->assertStatus(403);
});

it('adds a candidate as a SCRAPED_UV blank in the gate with the plain product link', function (): void {
    Sanctum::actingAs($this->staff);
    $res = $this->postJson('/api/admin/blank-recommendations/add', [
        'source_product_id' => '3_4', 'name' => 'Plain Ceramic Mug 440ml', 'price' => 9.90,
        'image_url' => 'https://i/2', 'product_link' => 'https://shopee.sg/product/3/4',
    ])->assertOk();

    $product = Product::findOrFail($res->json('data.id'));
    expect($product->class->value)->toBe('SCRAPED_UV')
        ->and($product->publish_state->value)->toBe('CANNOT_PUBLISH')
        ->and($product->source_links[0]['url'])->toBe('https://shopee.sg/product/3/4');
});

it('features + unfeatures a candidate for the public page', function (): void {
    Sanctum::actingAs($this->staff);
    $this->postJson('/api/admin/blank-recommendations/feature', [
        'source_product_id' => '3_4', 'name' => 'Plain Ceramic Mug 440ml', 'price' => 9.90,
        'image_url' => 'https://i/2', 'offer_link' => 'https://s.shopee.sg/bb',
        'product_link' => 'https://shopee.sg/product/3/4', 'shop_name' => 'S2', 'ip_flagged' => false,
    ])->assertOk();

    $f = GiftIdeaFeature::where('source_product_id', '3_4')->firstOrFail();
    expect($f->offer_link)->toBe('https://s.shopee.sg/bb');

    $this->deleteJson("/api/admin/blank-recommendations/feature/{$f->id}")->assertOk();
    expect(GiftIdeaFeature::where('source_product_id', '3_4')->exists())->toBeFalse();
});

it('busts the public gift-ideas cache when featuring', function (): void {
    Sanctum::actingAs($this->staff);

    Cache::put(GiftIdeasController::CACHE_KEY, ['stale'], now()->addHour());
    $this->postJson('/api/admin/blank-recommendations/feature', [
        'source_product_id' => '3_4', 'name' => 'Plain Ceramic Mug 440ml', 'price' => 9.90,
        'image_url' => 'https://i/2', 'offer_link' => 'https://s.shopee.sg/bb',
        'product_link' => 'https://shopee.sg/product/3/4', 'shop_name' => 'S2', 'ip_flagged' => false,
    ])->assertOk();

    expect(Cache::has(GiftIdeasController::CACHE_KEY))->toBeFalse();

    $f = GiftIdeaFeature::where('source_product_id', '3_4')->firstOrFail();

    Cache::put(GiftIdeasController::CACHE_KEY, ['stale'], now()->addHour());
    $this->deleteJson("/api/admin/blank-recommendations/feature/{$f->id}")->assertOk();

    expect(Cache::has(GiftIdeasController::CACHE_KEY))->toBeFalse();
});

it('preserves created_by when a feature is updated', function (): void {
    $originalCreator = User::factory()->staffAdmin()->create();
    $feature = GiftIdeaFeature::factory()->create([
        'source_product_id' => 'KEEP_1',
        'name' => 'Original Name',
        'created_by' => $originalCreator->id,
    ]);

    Sanctum::actingAs($this->staff);
    $this->postJson('/api/admin/blank-recommendations/feature', [
        'source_product_id' => 'KEEP_1', 'name' => 'Updated Name', 'price' => 12.50,
        'image_url' => 'https://i/3', 'offer_link' => 'https://s.shopee.sg/cc',
        'product_link' => 'https://shopee.sg/product/keep/1', 'shop_name' => 'S3', 'ip_flagged' => false,
    ])->assertOk();

    $feature->refresh();
    expect($feature->created_by)->toBe($originalCreator->id)
        ->and($feature->name)->toBe('Updated Name');
});
