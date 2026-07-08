<?php

declare(strict_types=1);

use App\Services\Scraper\HttpShopeeAffiliateClient;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('services.shopee_affiliate.app_id', 'app-123');
    config()->set('services.shopee_affiliate.secret', 'secret-xyz');
    config()->set('services.shopee_affiliate.base_url', 'https://open-api.affiliate.shopee.sg/graphql');
    $this->client = app(HttpShopeeAffiliateClient::class);
});

it('fetches a listing and maps the feed fields', function (): void {
    Http::fake([
        'open-api.affiliate.shopee.sg/*' => Http::response([
            'data' => ['productOfferV2' => ['nodes' => [[
                'itemId' => 4567,
                'productName' => 'Ceramic Mug 350ml',
                'priceMin' => '3.90',
                'imageUrl' => 'https://cf.shopee.sg/file/mug.jpg',
                'productLink' => 'https://shopee.sg/product/111/4567',
            ]]]],
        ], 200),
    ]);

    $data = $this->client->fetch('111_4567');

    expect($data)->not->toBeNull()
        ->and($data->name)->toBe('Ceramic Mug 350ml')
        ->and($data->price)->toBe(3.90)
        ->and($data->sourceUrl)->toBe('https://shopee.sg/product/111/4567')
        // Physical attributes are staff judgements - never faked from the feed.
        ->and($data->dimensions)->toBeNull()
        ->and($data->weight)->toBeNull()
        ->and($data->printable)->toBeFalse();
});

it('signs requests with the SHA256 credential scheme', function (): void {
    Http::fake([
        'open-api.affiliate.shopee.sg/*' => Http::response([
            'data' => ['productOfferV2' => ['nodes' => []]],
        ], 200),
    ]);

    $this->client->fetch('4567');

    Http::assertSent(function ($request): bool {
        $auth = $request->header('Authorization')[0] ?? '';
        preg_match('/^SHA256 Credential=app-123, Timestamp=(\d+), Signature=([0-9a-f]{64})$/', $auth, $m);

        if ($m === []) {
            return false;
        }

        return $m[2] === hash('sha256', 'app-123'.$m[1].$request->body().'secret-xyz');
    });
});

it('marks a removed listing as source-dead', function (): void {
    Http::fake([
        'open-api.affiliate.shopee.sg/*' => Http::response([
            'data' => ['productOfferV2' => ['nodes' => []]],
        ], 200),
    ]);

    $data = $this->client->fetch('111_999');

    expect($data)->not->toBeNull()
        ->and($data->sourceDead)->toBeTrue();
});

it('returns null on upstream failure so the core flow degrades gracefully', function (): void {
    Http::fake(['open-api.affiliate.shopee.sg/*' => Http::response('nope', 500)]);

    expect($this->client->fetch('111_4567'))->toBeNull();
});

it('maps search hits to gate-ready listings', function (): void {
    Http::fake([
        'open-api.affiliate.shopee.sg/*' => Http::response([
            'data' => ['productOfferV2' => ['nodes' => [
                ['itemId' => 1, 'shopId' => 9, 'productName' => 'Mug A', 'priceMin' => '2.50', 'imageUrl' => null, 'productLink' => 'https://shopee.sg/p/1'],
                ['itemId' => 2, 'shopId' => 9, 'productName' => 'Mug B', 'priceMin' => '4.00', 'imageUrl' => null, 'productLink' => 'https://shopee.sg/p/2'],
            ]]],
        ], 200),
    ]);

    $hits = $this->client->search('mug', 10);

    expect($hits)->toHaveCount(2)
        ->and($hits[0]->sourceProductId)->toBe('9_1')
        ->and($hits[1]->price)->toBe(4.00);
});
