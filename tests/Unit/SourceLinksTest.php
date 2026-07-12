<?php

declare(strict_types=1);

use App\Support\SourceLinks;

it('guesses marketplace kind from a shopee host', function (): void {
    expect(SourceLinks::guessKind('https://shopee.sg/product/1/2'))->toBe('marketplace');
    expect(SourceLinks::guessKind('https://www.lazada.sg/products/x.html'))->toBe('marketplace');
});

it('guesses local kind for any other host', function (): void {
    expect(SourceLinks::guessKind('https://blankco.sg/mug'))->toBe('local');
});

it('normalizes a link, filling defaults', function (): void {
    $link = SourceLinks::normalize(['url' => 'https://shopee.sg/product/1/2', 'price' => 9.9]);

    expect($link['url'])->toBe('https://shopee.sg/product/1/2')
        ->and($link['kind'])->toBe('marketplace')
        ->and($link['currency'])->toBe('SGD')
        ->and($link['label'])->toBe('shopee.sg')
        ->and($link)->toHaveKey('last_checked');
});

it('adds a link and dedupes by url (last write wins on price)', function (): void {
    $links = SourceLinks::add([], ['url' => 'https://a.sg/1', 'price' => 10.0]);
    $links = SourceLinks::add($links, ['url' => 'https://a.sg/1', 'price' => 11.0]);

    expect($links)->toHaveCount(1)
        ->and($links[0]['price'])->toBe(11.0);
});

it('returns the first local url as primary, else the first', function (): void {
    $links = [
        SourceLinks::normalize(['url' => 'https://shopee.sg/product/1/2']),
        SourceLinks::normalize(['url' => 'https://blankco.sg/mug']),
    ];
    expect(SourceLinks::primaryUrl($links))->toBe('https://blankco.sg/mug');
    expect(SourceLinks::primaryUrl([$links[0]]))->toBe('https://shopee.sg/product/1/2');
    expect(SourceLinks::primaryUrl([]))->toBeNull();
});
