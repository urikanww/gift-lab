<?php

declare(strict_types=1);

use App\Models\LineItem;
use App\Models\Product;
use App\Models\Quote;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Variant;
use Laravel\Sanctum\Sanctum;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    $this->staff = User::factory()->staffAdmin()->create();
    $this->product = Product::factory()->create(['class' => 'SCRAPED_UV', 'print_method' => 'UV']);
});

it('soft-deletes a variant: gone from the product, still resolvable for past orders', function (): void {
    Sanctum::actingAs($this->staff);
    $variant = Variant::factory()->create(['product_id' => $this->product->id, 'attributes' => ['option' => 'M']]);
    $quote = Quote::factory()->create();
    $line = LineItem::factory()->create(['quote_id' => $quote->id, 'product_id' => $this->product->id, 'variant_id' => $variant->id]);

    $this->deleteJson("/api/admin/variants/{$variant->id}")
        ->assertOk()
        ->assertJsonPath('data.archived', true);

    expect($this->product->variants()->count())->toBe(0)
        ->and(Variant::withTrashed()->find($variant->id))->not->toBeNull()
        ->and($line->fresh()->variant()->withTrashed()->first()?->id)->toBe($variant->id);
});

it('403s a non-staff user archiving a variant', function (): void {
    $variant = Variant::factory()->create(['product_id' => $this->product->id]);
    Sanctum::actingAs(User::factory()->create(['role' => 'buyer']));

    $this->deleteJson("/api/admin/variants/{$variant->id}")->assertForbidden();
});

it('updates a variant price delta with no stock movement when stock is unchanged', function (): void {
    Sanctum::actingAs($this->staff);
    $variant = Variant::factory()->create(['product_id' => $this->product->id, 'price_delta' => 0, 'stock_on_hand' => 0]);

    $this->patchJson("/api/admin/variants/{$variant->id}", ['price_delta' => 2.5])
        ->assertOk();

    expect((float) $variant->fresh()->price_delta)->toBe(2.5)
        ->and(StockMovement::query()->count())->toBe(0);
});
