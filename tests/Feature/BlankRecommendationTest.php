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
    // Nodes are returned in the order Shopee sorted them (server-side); the
    // controller preserves this order verbatim.
    Http::fake(['aff.test/*' => Http::response(['data' => ['productOfferV2' => ['nodes' => [
        ['itemId' => 4, 'shopId' => 3, 'productName' => 'Plain Ceramic Mug 440ml', 'priceMin' => '9.90', 'imageUrl' => 'https://i/2', 'productLink' => 'https://shopee.sg/product/3/4', 'offerLink' => 'https://s.shopee.sg/bb', 'sales' => 300, 'ratingStar' => '4.9', 'shopName' => 'S2', 'commissionRate' => '0.18'],
        ['itemId' => 2, 'shopId' => 1, 'productName' => 'Disney Ceramic Mug', 'priceMin' => '20.00', 'imageUrl' => 'https://i/1', 'productLink' => 'https://shopee.sg/product/1/2', 'offerLink' => 'https://s.shopee.sg/aa', 'sales' => 10, 'ratingStar' => '4.5', 'shopName' => 'S1', 'commissionRate' => '0.12'],
    ]]]], 200)]);
}

it('preserves Shopee order and exposes flags + commission (staff only)', function (): void {
    fakeCandidates();
    Sanctum::actingAs($this->staff);
    $res = $this->getJson('/api/admin/blank-recommendations?keyword=mug&limit=10')->assertOk();

    $data = $res->json('data');
    // Order matches Shopee's node order (no local re-sort).
    expect($data[0]['source_product_id'])->toBe('3_4')
        ->and($data[0]['ip_flag'])->toBeNull()
        ->and($data[0]['commission_rate'])->toBe(0.18)
        ->and(collect($data)->firstWhere('source_product_id', '1_2')['ip_flag'])->toBe('disney');
});

it('browses the top-sales feed with an empty keyword (first load)', function (): void {
    fakeCandidates();
    Sanctum::actingAs($this->staff);

    $res = $this->getJson('/api/admin/blank-recommendations?keyword=')->assertOk();
    expect($res->json('data'))->toHaveCount(2);

    Http::assertSent(function ($request): bool {
        $vars = json_decode($request->body(), true)['variables'] ?? [];

        // Keyword sent as null (browse), default sort = sales (2).
        return array_key_exists('keyword', $vars) && $vars['keyword'] === null
            && ($vars['sortType'] ?? null) === 2;
    });
});

it('maps the sort key to Shopee sortType and forwards it', function (): void {
    fakeCandidates();
    Sanctum::actingAs($this->staff);
    $this->getJson('/api/admin/blank-recommendations?keyword=mug&sort=commission')->assertOk();

    Http::assertSent(function ($request): bool {
        $body = json_decode($request->body(), true);

        return ($body['variables']['sortType'] ?? null) === 5; // commission = 5
    });
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

it('lists featured items for staff management (incl. IP-flagged)', function (): void {
    Sanctum::actingAs($this->staff);
    GiftIdeaFeature::factory()->create(['source_product_id' => 'A_1', 'name' => 'Public Mug', 'ip_flagged' => false]);
    GiftIdeaFeature::factory()->create(['source_product_id' => 'B_2', 'name' => 'Hidden IP Mug', 'ip_flagged' => true]);

    $data = $this->getJson('/api/admin/blank-recommendations/featured')->assertOk()->json('data');

    $names = collect($data)->pluck('name');
    expect($names)->toContain('Public Mug')->toContain('Hidden IP Mug')
        ->and(collect($data)->firstWhere('source_product_id', 'A_1'))->toHaveKeys(['id', 'offer_link', 'ip_flagged']);
});

it('forbids non-staff on the featured list', function (): void {
    Sanctum::actingAs(User::factory()->create(['role' => 'buyer']));
    $this->getJson('/api/admin/blank-recommendations/featured')->assertStatus(403);
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
