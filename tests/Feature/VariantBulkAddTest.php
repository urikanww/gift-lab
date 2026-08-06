<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Variant;
use Laravel\Sanctum\Sanctum;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    $this->staff = User::factory()->staffAdmin()->create();
    $this->product = Product::factory()->create(['class' => 'SCRAPED_UV', 'print_method' => 'UV']);
});

it('bulk-creates variants at stock 0 with no stock movement', function (): void {
    Sanctum::actingAs($this->staff);

    $this->postJson("/api/admin/products/{$this->product->id}/variants/bulk", [
        'variants' => [
            ['option' => 'S / Black', 'price_delta' => 0],
            ['option' => 'S / White', 'price_delta' => 0],
            ['option' => 'M / Black', 'price_delta' => 1.5],
        ],
    ])
        ->assertOk()
        ->assertJsonPath('data.created', 3)
        ->assertJsonPath('data.skipped', 0);

    expect(Variant::query()->where('product_id', $this->product->id)->count())->toBe(3)
        ->and((int) Variant::query()->where('product_id', $this->product->id)->sum('stock_on_hand'))->toBe(0)
        ->and(StockMovement::query()->count())->toBe(0);
});

it('skips options that already exist, case-insensitively', function (): void {
    Sanctum::actingAs($this->staff);
    Variant::factory()->create(['product_id' => $this->product->id, 'attributes' => ['option' => 'S / Black']]);

    $this->postJson("/api/admin/products/{$this->product->id}/variants/bulk", [
        'variants' => [
            ['option' => 's / black'],
            ['option' => 'M / Black'],
        ],
    ])
        ->assertOk()
        ->assertJsonPath('data.created', 1)
        ->assertJsonPath('data.skipped', 1);

    expect(Variant::query()->where('product_id', $this->product->id)->count())->toBe(2);
});

it('de-dupes duplicate options within one request', function (): void {
    Sanctum::actingAs($this->staff);

    $this->postJson("/api/admin/products/{$this->product->id}/variants/bulk", [
        'variants' => [['option' => 'M / Black'], ['option' => 'M / Black']],
    ])
        ->assertOk()
        ->assertJsonPath('data.created', 1);
});

it('rejects an empty list and an over-cap list', function (): void {
    Sanctum::actingAs($this->staff);

    $this->postJson("/api/admin/products/{$this->product->id}/variants/bulk", ['variants' => []])
        ->assertStatus(422);

    $tooMany = array_map(fn (int $i): array => ['option' => "V{$i}"], range(1, 201));
    $this->postJson("/api/admin/products/{$this->product->id}/variants/bulk", ['variants' => $tooMany])
        ->assertStatus(422);
});

it('403s a non-staff user', function (): void {
    Sanctum::actingAs(User::factory()->create(['role' => 'buyer']));

    $this->postJson("/api/admin/products/{$this->product->id}/variants/bulk", [
        'variants' => [['option' => 'M / Black']],
    ])->assertForbidden();
});
