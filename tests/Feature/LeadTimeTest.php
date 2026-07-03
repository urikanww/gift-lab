<?php

declare(strict_types=1);

use App\Models\Product;

beforeEach(function (): void {
    seedPricing();
});

it('estimates a conservative, ranged delivery window on an empty queue', function (): void {
    $product = Product::factory()->create(['class' => 'CORE', 'publish_state' => 'PUBLISHED']);

    $res = $this->postJson('/api/lead-time-estimate', [
        'line_items' => [['product_id' => $product->id]],
    ])->assertOk();

    // UV base 3 + queue 0 + dispatch 2 = 5 earliest; +3 buffer = 8 latest.
    $res->assertJsonPath('production_days', 5)
        ->assertJsonPath('queue_depth', 0)
        ->assertJsonPath('rush_available', true);

    $earliest = $res->json('earliest');
    $latest = $res->json('latest');
    expect(strtotime($latest))->toBeGreaterThan(strtotime($earliest))
        ->and($res->json('rush_earliest'))->not->toBeNull();
});

it('gates a mixed order by the slower (3D) track', function (): void {
    $uv = Product::factory()->create(['class' => 'CORE', 'publish_state' => 'PUBLISHED']);
    $threeD = Product::factory()->create(['class' => 'MODEL_3D', 'publish_state' => 'PUBLISHED']);

    // max(UV 3, 3D 5) + dispatch 2 = 7.
    $this->postJson('/api/lead-time-estimate', [
        'line_items' => [['product_id' => $uv->id], ['product_id' => $threeD->id]],
    ])
        ->assertOk()
        ->assertJsonPath('production_days', 7);
});

it('rejects an unpublished product so it cannot leak drafts', function (): void {
    $draft = Product::factory()->create(['class' => 'CORE', 'publish_state' => 'PENDING']);

    $this->postJson('/api/lead-time-estimate', [
        'line_items' => [['product_id' => $draft->id]],
    ])->assertStatus(422);
});
