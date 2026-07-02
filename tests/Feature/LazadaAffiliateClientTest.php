<?php

declare(strict_types=1);

use App\Services\Scraper\CompositeScraperClient;
use App\Services\Scraper\FixtureScraperClient;
use App\Services\Scraper\HttpLazadaAffiliateClient;
use App\Services\Scraper\ScrapedProductData;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('services.lazada_affiliate.app_key', 'lz-app');
    config()->set('services.lazada_affiliate.secret', 'lz-secret');
    config()->set('services.lazada_affiliate.base_url', 'https://api.lazada.sg/rest');
    $this->client = app(HttpLazadaAffiliateClient::class);
});

it('fetches a listing and maps the feed fields', function (): void {
    Http::fake([
        'api.lazada.sg/*' => Http::response([
            'code' => '0',
            'data' => ['products' => [[
                'product_id' => 777,
                'product_name' => 'Stainless Tumbler 500ml',
                'app_price' => '6.20',
                'image_url' => 'https://sg-live.slatic.net/p/tumbler.jpg',
                'product_url' => 'https://www.lazada.sg/products/i777.html',
            ]]],
        ], 200),
    ]);

    $data = $this->client->fetch('lazada:777');

    expect($data)->not->toBeNull()
        ->and($data->sourceProductId)->toBe('lazada:777')
        ->and($data->name)->toBe('Stainless Tumbler 500ml')
        ->and($data->price)->toBe(6.20)
        ->and($data->printable)->toBeFalse();
});

it('signs requests with the uppercase HMAC-SHA256 open-platform scheme', function (): void {
    Http::fake([
        'api.lazada.sg/*' => Http::response(['code' => '0', 'data' => ['products' => []]], 200),
    ]);

    $this->client->fetch('lazada:1');

    Http::assertSent(function ($request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $params);
        $sign = $params['sign'] ?? '';
        unset($params['sign']);
        ksort($params);

        $canonical = '/marketing/product/detail';
        foreach ($params as $key => $value) {
            $canonical .= $key.$value;
        }

        return $sign === strtoupper(hash_hmac('sha256', $canonical, 'lz-secret'));
    });
});

it('marks a removed listing as source-dead', function (): void {
    Http::fake(['api.lazada.sg/*' => Http::response(['code' => '0', 'data' => ['products' => []]], 200)]);

    expect($this->client->fetch('lazada:404')->sourceDead)->toBeTrue();
});

it('returns null on an in-body error code', function (): void {
    Http::fake(['api.lazada.sg/*' => Http::response(['code' => 'IllegalAccessToken', 'message' => 'nope'], 200)]);

    expect($this->client->fetch('lazada:1'))->toBeNull();
});

it('routes prefixed ids to lazada and everything else to shopee', function (): void {
    $shopee = (new FixtureScraperClient())->with(new ScrapedProductData(
        sourceProductId: '9_1', sourceUrl: 'https://shopee.sg/p/1', name: 'Shopee Mug',
        price: 2.5, dimensions: null, weight: null, stockEstimate: null, imageUrl: null, printable: false,
    ));
    $lazada = (new FixtureScraperClient())->with(new ScrapedProductData(
        sourceProductId: 'lazada:7', sourceUrl: 'https://lazada.sg/p/7', name: 'Lazada Tumbler',
        price: 6.0, dimensions: null, weight: null, stockEstimate: null, imageUrl: null, printable: false,
    ));

    $composite = new CompositeScraperClient($shopee, $lazada, new FixtureScraperClient());

    expect($composite->fetch('9_1')?->name)->toBe('Shopee Mug')
        ->and($composite->fetch('lazada:7')?->name)->toBe('Lazada Tumbler');
});
