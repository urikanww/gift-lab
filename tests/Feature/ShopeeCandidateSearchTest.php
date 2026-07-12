<?php

declare(strict_types=1);

use App\Services\Scraper\HttpShopeeAffiliateClient;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config([
        'services.shopee_affiliate.app_id' => 'test-app',
        'services.shopee_affiliate.secret' => 'test-secret',
        'services.shopee_affiliate.base_url' => 'https://aff.test/graphql',
    ]);
});

it('maps affiliate nodes into ShopeeCandidate objects', function (): void {
    Http::fake(['aff.test/*' => Http::response(['data' => ['productOfferV2' => ['nodes' => [
        [
            'itemId' => 26094497054, 'shopId' => 1505484155,
            'productName' => 'Embossed Tulip Ceramic Mug 440ml', 'priceMin' => '25.90',
            'imageUrl' => 'https://cf.shopee.sg/mug.jpg',
            'productLink' => 'https://shopee.sg/product/1505484155/26094497054',
            'offerLink' => 'https://s.shopee.sg/abc123',
            'sales' => 320, 'ratingStar' => '4.8', 'shopName' => 'CeramicCo',
        ],
    ]]]], 200)]);

    $out = app(HttpShopeeAffiliateClient::class)->searchCandidates('ceramic mug', 5);

    expect($out)->toHaveCount(1);
    $c = $out[0];
    expect($c->sourceProductId)->toBe('1505484155_26094497054')
        ->and($c->name)->toBe('Embossed Tulip Ceramic Mug 440ml')
        ->and($c->price)->toBe(25.90)
        ->and($c->productLink)->toBe('https://shopee.sg/product/1505484155/26094497054')
        ->and($c->offerLink)->toBe('https://s.shopee.sg/abc123')
        ->and($c->sales)->toBe(320)
        ->and($c->ratingStar)->toBe(4.8)
        ->and($c->shopName)->toBe('CeramicCo');
});

it('returns empty when credentials are missing', function (): void {
    config(['services.shopee_affiliate.app_id' => '', 'services.shopee_affiliate.secret' => '']);
    expect(app(HttpShopeeAffiliateClient::class)->searchCandidates('mug', 5))->toBe([]);
});
