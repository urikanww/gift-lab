<?php

declare(strict_types=1);

use App\Services\Catalogue\ListingCapture;
use Illuminate\Support\Facades\Http;

it('extracts name/price/image from JSON-LD Product', function (): void {
    Http::fake(['*' => Http::response(<<<'HTML'
        <html><head>
        <script type="application/ld+json">
        {"@type":"Product","name":"Ceramic Mug 440ml","image":"https://cdn.sg/mug.jpg",
         "offers":{"@type":"Offer","price":"12.90","priceCurrency":"SGD"}}
        </script>
        </head><body></body></html>
        HTML, 200)]);

    $data = app(ListingCapture::class)->capture('https://blankco.sg/mug-440');

    expect($data->name)->toBe('Ceramic Mug 440ml')
        ->and($data->price)->toBe(12.90)
        ->and($data->imageUrl)->toBe('https://cdn.sg/mug.jpg')
        ->and($data->sourceUrl)->toBe('https://blankco.sg/mug-440')
        ->and($data->printable)->toBeFalse();
});

it('falls back to Open Graph tags when no JSON-LD', function (): void {
    Http::fake(['*' => Http::response(<<<'HTML'
        <html><head>
        <meta property="og:title" content="Blank Tumbler 600ml">
        <meta property="og:image" content="https://cdn.sg/tumbler.png">
        <meta property="product:price:amount" content="8.50">
        </head><body></body></html>
        HTML, 200)]);

    $data = app(ListingCapture::class)->capture('https://blankco.sg/tumbler');

    expect($data->name)->toBe('Blank Tumbler 600ml')
        ->and($data->price)->toBe(8.50)
        ->and($data->imageUrl)->toBe('https://cdn.sg/tumbler.png');
});

it('extracts Open Graph tags emitted in reversed attribute order', function (): void {
    Http::fake(['*' => Http::response(<<<'HTML'
        <html><head>
        <meta content="Reversed Mug" property="og:title">
        <meta content="https://cdn.sg/reversed.png" property="og:image">
        <meta content="9.90" property="product:price:amount">
        </head><body></body></html>
        HTML, 200)]);

    $data = app(ListingCapture::class)->capture('https://blankco.sg/reversed');

    expect($data->name)->toBe('Reversed Mug')
        ->and($data->imageUrl)->toBe('https://cdn.sg/reversed.png')
        ->and($data->price)->toBe(9.90);
});

it('derives shopId_itemId as sourceProductId for a shopee url', function (): void {
    Http::fake(['*' => Http::response('<html><head><title>x</title></head></html>', 200)]);

    $data = app(ListingCapture::class)->capture('https://shopee.sg/product/1505484155/49207854779');

    expect($data->sourceProductId)->toBe('1505484155_49207854779');
});

it('returns null on a failed fetch', function (): void {
    Http::fake(['*' => Http::response('', 500)]);

    expect(app(ListingCapture::class)->capture('https://blankco.sg/down'))->toBeNull();
});
