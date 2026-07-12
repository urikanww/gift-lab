<?php

declare(strict_types=1);

use App\Models\GiftIdeaFeature;
use Illuminate\Support\Facades\Cache;

beforeEach(fn () => Cache::flush());

it('returns non-IP-flagged features publicly with offer links', function (): void {
    GiftIdeaFeature::factory()->create(['name' => 'Plain Mug', 'ip_flagged' => false, 'sort' => 1, 'offer_link' => 'https://s.shopee.sg/ok']);
    GiftIdeaFeature::factory()->create(['name' => 'Disney Mug', 'ip_flagged' => true, 'sort' => 2]);

    $res = $this->getJson('/api/gift-ideas')->assertOk();
    $names = collect($res->json('data'))->pluck('name');

    expect($names)->toContain('Plain Mug')->not->toContain('Disney Mug')
        ->and($res->json('data.0.offer_link'))->toBe('https://s.shopee.sg/ok');
});

it('does not leak internal fields', function (): void {
    GiftIdeaFeature::factory()->create(['ip_flagged' => false]);
    $row = $this->getJson('/api/gift-ideas')->assertOk()->json('data.0');
    expect($row)->not->toHaveKeys(['product_link', 'created_by', 'id', 'source_product_id']);
});

it('needs no auth', function (): void {
    $this->getJson('/api/gift-ideas')->assertOk();
});
