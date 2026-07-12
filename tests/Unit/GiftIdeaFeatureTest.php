<?php

declare(strict_types=1);

use App\Models\GiftIdeaFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('persists a featured gift idea and casts flags', function (): void {
    $f = GiftIdeaFeature::factory()->create(['ip_flagged' => true, 'price' => 12.50]);
    expect($f->fresh()->ip_flagged)->toBeTrue()
        ->and((float) $f->fresh()->price)->toBe(12.50);
});

it('is unique on source_product_id via updateOrCreate', function (): void {
    GiftIdeaFeature::updateOrCreate(['source_product_id' => 'S_1'], ['name' => 'A', 'offer_link' => 'https://s/1', 'product_link' => 'https://p/1']);
    GiftIdeaFeature::updateOrCreate(['source_product_id' => 'S_1'], ['name' => 'B', 'offer_link' => 'https://s/1', 'product_link' => 'https://p/1']);
    expect(GiftIdeaFeature::where('source_product_id', 'S_1')->count())->toBe(1)
        ->and(GiftIdeaFeature::where('source_product_id', 'S_1')->first()->name)->toBe('B');
});
