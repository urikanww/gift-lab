<?php

declare(strict_types=1);

use App\Models\PricingConfig;
use App\Models\Product;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    seedPricing();
    $this->staff = User::factory()->staffAdmin()->create();
    $this->superadmin = User::factory()->superadmin()->create();
});

it('lists scraped and 3D items for staff', function (): void {
    Product::factory()->scrapedUv()->create(['publish_state' => 'READY_TO_APPROVE']);
    Product::factory()->model3d()->create(['publish_state' => 'CANNOT_PUBLISH']);
    Product::factory()->create(['class' => 'CORE']); // excluded

    Sanctum::actingAs($this->staff);
    $response = $this->getJson('/api/admin/catalogue')->assertOk();

    expect($response->json('data'))->toHaveCount(2);
});

it('publishes an item awaiting approval', function (): void {
    $product = Product::factory()->scrapedUv()->create(['publish_state' => 'READY_TO_APPROVE']);

    Sanctum::actingAs($this->staff);
    $this->postJson("/api/admin/products/{$product->id}/publish")
        ->assertOk()
        ->assertJsonPath('publish_state', 'PUBLISHED');
});

it('refuses to publish a CANNOT_PUBLISH item', function (): void {
    $product = Product::factory()->scrapedUv()->create(['publish_state' => 'CANNOT_PUBLISH']);

    Sanctum::actingAs($this->staff);
    $this->postJson("/api/admin/products/{$product->id}/publish")->assertStatus(422);
});

it('forbids non-staff from the admin catalogue', function (): void {
    $buyer = User::factory()->create(['role' => 'buyer']);
    Sanctum::actingAs($buyer);
    $this->getJson('/api/admin/catalogue')->assertForbidden();
});

it('lets only a superadmin toggle auto-publish', function (): void {
    Sanctum::actingAs($this->staff);
    $this->patchJson('/api/admin/settings/auto-publish', ['enabled' => true])->assertForbidden();

    Sanctum::actingAs($this->superadmin);
    $this->patchJson('/api/admin/settings/auto-publish', ['enabled' => true])
        ->assertOk()
        ->assertJsonPath('auto_publish', true);

    expect((bool) PricingConfig::value('catalogue', 'auto_publish', false))->toBeTrue();
});
