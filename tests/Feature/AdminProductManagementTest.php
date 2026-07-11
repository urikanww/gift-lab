<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Quote;
use App\Models\User;
use App\Models\Variant;
use App\Services\PricingService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

// Phase 4a: product list sort/filter/search/sold_count, bulk-publish, and
// image upload/remove on AdminProductController.

beforeEach(function (): void {
    seedPricing();
    $this->company = Company::factory()->create();
    $this->buyer = User::factory()->create(['company_id' => $this->company->id, 'role' => 'buyer']);
    $this->staff = User::factory()->staffAdmin()->create();
    $this->superadmin = User::factory()->create(['role' => 'superadmin', 'company_id' => null]);
});

/**
 * Build a quote in the given state with one line item for the given product/qty,
 * bypassing the state machine guard (direct DB writes) since we only need the
 * end state for the sold_count aggregate, not a legally-transitioned history.
 */
function makeQuoteWithLineItem(Company $company, Product $product, int $qty, string $state): LineItem
{
    $quote = Quote::factory()->create(['company_id' => $company->id, 'state' => $state]);

    return LineItem::factory()->create([
        'quote_id' => $quote->id,
        'product_id' => $product->id,
        'qty' => $qty,
    ]);
}

it('exposes source_url on the product detail so the admin can jump to the listing', function (): void {
    $product = Product::factory()->model3d()->create([
        'source_url' => 'https://makerworld.com/en/models/3015782',
        'source_product_id' => '3015782',
    ]);

    Sanctum::actingAs($this->staff);
    $this->getJson("/api/admin/products/{$product->id}")
        ->assertOk()
        ->assertJsonPath('data.source_url', 'https://makerworld.com/en/models/3015782')
        ->assertJsonPath('data.source_product_id', '3015782');
});

it('lets staff edit a MODEL_3D est_grams/est_print_minutes and reprices the base cost', function (): void {
    // The 3D base cost = est_grams × filament_per_gram + est_print_minutes ×
    // machine_rate_per_min (seedPricing sets 0.05 and 0.08). Editing the inputs
    // via the product-update endpoint must persist + move the computed cost.
    $product = Product::factory()->model3d()->create([
        'est_grams' => 100,
        'est_print_minutes' => 200,
    ]);

    Sanctum::actingAs($this->staff);
    $res = $this->patchJson("/api/admin/products/{$product->id}", [
        'est_grams' => 150,
        'est_print_minutes' => 400,
    ])->assertOk();

    $product->refresh();
    expect((float) $product->est_grams)->toBe(150.0)
        ->and((float) $product->est_print_minutes)->toBe(400.0);

    // Serializer exposes them, and the recomputed landed cost = 150×0.05 + 400×0.08 = 39.5.
    $res->assertJsonPath('data.est_grams', '150.000')
        ->assertJsonPath('data.est_print_minutes', '400.0');
    expect((float) app(PricingService::class)->landedCost($product, null))->toBe(39.5);
});

it('sorts by most_sold using summed qty from won quotes only', function (): void {
    $hot = Product::factory()->create(['name' => 'Hot Seller']);
    $warm = Product::factory()->create(['name' => 'Warm Seller']);
    $cold = Product::factory()->create(['name' => 'Cold Seller']);

    // Hot: 5 (ACCEPTED) + 10 (CLOSED) = 15 won.
    makeQuoteWithLineItem($this->company, $hot, 5, 'ACCEPTED');
    makeQuoteWithLineItem($this->company, $hot, 10, 'CLOSED');

    // Warm: 7 (CONFIRMED) won, plus 100 in a DRAFT quote that must NOT count.
    makeQuoteWithLineItem($this->company, $warm, 7, 'CONFIRMED');
    makeQuoteWithLineItem($this->company, $warm, 100, 'DRAFT');

    // Cold: only a CANCELLED quote - must not count, sold_count 0.
    makeQuoteWithLineItem($this->company, $cold, 50, 'CANCELLED');

    Sanctum::actingAs($this->staff);
    $data = collect($this->getJson('/api/admin/products?sort=most_sold')->assertOk()->json('data'));

    $ids = $data->pluck('id')->values()->all();
    expect($ids)->toBe([$hot->id, $warm->id, $cold->id]);

    expect($data->firstWhere('id', $hot->id)['sold_count'])->toBe(15)
        ->and($data->firstWhere('id', $warm->id)['sold_count'])->toBe(7)
        ->and($data->firstWhere('id', $cold->id)['sold_count'])->toBe(0);
});

it('does not count SENT or CHANGES_REQUESTED quotes as sold', function (): void {
    $product = Product::factory()->create(['name' => 'Pending Sales']);

    makeQuoteWithLineItem($this->company, $product, 20, 'SENT');
    makeQuoteWithLineItem($this->company, $product, 30, 'CHANGES_REQUESTED');

    Sanctum::actingAs($this->staff);
    $data = collect($this->getJson('/api/admin/products')->assertOk()->json('data'));

    expect($data->firstWhere('id', $product->id)['sold_count'])->toBe(0);
});

it('filters by q (case-insensitive name search)', function (): void {
    Product::factory()->create(['name' => 'Ceramic Mug 11oz']);
    Product::factory()->create(['name' => 'Stainless Tumbler']);

    Sanctum::actingAs($this->staff);
    $data = collect($this->getJson('/api/admin/products?q=mug')->assertOk()->json('data'));

    expect($data)->toHaveCount(1)
        ->and($data->first()['name'])->toBe('Ceramic Mug 11oz');
});

it('filters by publish_state', function (): void {
    Product::factory()->create(['name' => 'Ready One', 'class' => 'SCRAPED_UV', 'publish_state' => 'READY_TO_APPROVE']);
    Product::factory()->create(['name' => 'Published One', 'publish_state' => 'PUBLISHED']);

    Sanctum::actingAs($this->staff);
    $data = collect($this->getJson('/api/admin/products?publish_state=READY_TO_APPROVE')->assertOk()->json('data'));

    expect($data)->toHaveCount(1)
        ->and($data->first()['name'])->toBe('Ready One');
});

it('filters by category', function (): void {
    Product::factory()->create(['name' => 'Mug A', 'category' => 'drinkware']);
    Product::factory()->create(['name' => 'Tote B', 'category' => 'bags']);

    Sanctum::actingAs($this->staff);
    $data = collect($this->getJson('/api/admin/products?category=bags')->assertOk()->json('data'));

    expect($data)->toHaveCount(1)
        ->and($data->first()['name'])->toBe('Tote B');
});

it('sorts by name asc by default and base_cost/stock with sensible default directions', function (): void {
    $a = Product::factory()->create(['name' => 'Alpha', 'base_cost' => 5]);
    $b = Product::factory()->create(['name' => 'Beta', 'base_cost' => 50]);

    Variant::factory()->create(['product_id' => $a->id, 'stock_on_hand' => 100]);
    Variant::factory()->create(['product_id' => $b->id, 'stock_on_hand' => 10]);

    Sanctum::actingAs($this->staff);

    $byName = collect($this->getJson('/api/admin/products?sort=name')->assertOk()->json('data'))->pluck('id')->values()->all();
    expect($byName)->toBe([$a->id, $b->id]);

    $byCost = collect($this->getJson('/api/admin/products?sort=base_cost')->assertOk()->json('data'))->pluck('id')->values()->all();
    expect($byCost)->toBe([$a->id, $b->id]); // asc default: cheapest first

    $byStock = collect($this->getJson('/api/admin/products?sort=stock')->assertOk()->json('data'))->pluck('id')->values()->all();
    expect($byStock)->toBe([$a->id, $b->id]); // desc default: most stock first

    $stockTotals = collect($this->getJson('/api/admin/products?sort=stock')->assertOk()->json('data'));
    expect($stockTotals->firstWhere('id', $a->id)['stock_total'])->toBe(100)
        ->and($stockTotals->firstWhere('id', $b->id)['stock_total'])->toBe(10);
});

it('accepts an explicit dir override', function (): void {
    $a = Product::factory()->create(['name' => 'Alpha', 'base_cost' => 5]);
    $b = Product::factory()->create(['name' => 'Beta', 'base_cost' => 50]);

    Sanctum::actingAs($this->staff);
    $ids = collect($this->getJson('/api/admin/products?sort=base_cost&dir=desc')->assertOk()->json('data'))
        ->pluck('id')->values()->all();

    expect($ids)->toBe([$b->id, $a->id]);
});

it('paginates with per_page and total in meta', function (): void {
    Product::factory()->count(5)->create();

    Sanctum::actingAs($this->staff);
    $response = $this->getJson('/api/admin/products?per_page=2')->assertOk();

    expect($response->json('meta.total'))->toBe(5)
        ->and($response->json('data'))->toHaveCount(2);
});

it('bulk-publishes eligible products and reports per-item results', function (): void {
    $ready = Product::factory()->scrapedUv()->create([
        'publish_state' => 'READY_TO_APPROVE',
        'base_cost' => 5,
        'stock_estimate' => 10,
    ]);
    $notEligible = Product::factory()->create(['publish_state' => 'PENDING']);
    $alreadyPublished = Product::factory()->create(['publish_state' => 'PUBLISHED']);

    Sanctum::actingAs($this->staff);
    $response = $this->postJson('/api/admin/products/bulk-publish', [
        'ids' => [$ready->id, $notEligible->id, $alreadyPublished->id, 999999],
    ])->assertOk();

    $data = collect($response->json('data'));

    expect($data->firstWhere('id', $ready->id)['ok'])->toBeTrue()
        ->and($data->firstWhere('id', $notEligible->id)['ok'])->toBeFalse()
        ->and($data->firstWhere('id', $notEligible->id)['error'])->toBe('not eligible')
        ->and($data->firstWhere('id', $alreadyPublished->id)['ok'])->toBeFalse()
        ->and($data->firstWhere('id', 999999)['ok'])->toBeFalse();

    expect($response->json('meta.published'))->toBe(1)
        ->and($response->json('meta.failed'))->toBe(3);

    $ready->refresh();
    expect($ready->publish_state->value)->toBe('PUBLISHED');
});

it('rejects bulk-publish without ids or with a non-array/oversized payload', function (): void {
    Sanctum::actingAs($this->staff);

    $this->postJson('/api/admin/products/bulk-publish', [])->assertStatus(422);
    $this->postJson('/api/admin/products/bulk-publish', ['ids' => 'not-an-array'])->assertStatus(422);
    $this->postJson('/api/admin/products/bulk-publish', ['ids' => range(1, 201)])->assertStatus(422);
});

it('blocks non-staff from bulk-publish', function (): void {
    $product = Product::factory()->scrapedUv()->create(['publish_state' => 'READY_TO_APPROVE']);

    Sanctum::actingAs($this->buyer);
    $this->postJson('/api/admin/products/bulk-publish', ['ids' => [$product->id]])->assertForbidden();
});

it('uploads a product image, storing the file and setting image_url', function (): void {
    Storage::fake('public');
    $product = Product::factory()->create(['image_url' => null]);

    Sanctum::actingAs($this->staff);
    $response = $this->postJson("/api/admin/products/{$product->id}/image", [
        'image' => UploadedFile::fake()->image('photo.jpg'),
    ])->assertOk();

    $product->refresh();
    expect($product->image_url)->not->toBeNull()
        ->and($response->json('data.image_url'))->toBe($product->image_url);

    Storage::disk('public')->assertExists("products/product-{$product->id}.jpg");
    $this->assertDatabaseHas('audit_logs', ['event' => 'product.image_updated']);
});

it('rejects a non-image upload', function (): void {
    Storage::fake('public');
    $product = Product::factory()->create();

    Sanctum::actingAs($this->staff);
    $this->postJson("/api/admin/products/{$product->id}/image", [
        'image' => UploadedFile::fake()->create('evil.php', 10),
    ])->assertStatus(422);
});

it('removes a product image and clears image_url', function (): void {
    Storage::fake('public');
    Storage::disk('public')->put('products/product-existing.jpg', 'fake-bytes');

    $product = Product::factory()->create([
        'image_url' => url('storage/products/product-existing.jpg'),
    ]);
    // Rename the stored fixture to match the deterministic naming the delete
    // path expects, so we can assert real deletion happened.
    Storage::disk('public')->put("products/product-{$product->id}.jpg", 'fake-bytes');
    $product->update(['image_url' => url("storage/products/product-{$product->id}.jpg")]);

    Sanctum::actingAs($this->staff);
    $response = $this->deleteJson("/api/admin/products/{$product->id}/image")->assertOk();

    expect($response->json('data.image_url'))->toBeNull();
    $product->refresh();
    expect($product->image_url)->toBeNull();

    Storage::disk('public')->assertMissing("products/product-{$product->id}.jpg");
    $this->assertDatabaseHas('audit_logs', ['event' => 'product.image_removed']);
});

it('lets a superadmin set a price override, reflected in selling_price and audited', function (): void {
    $product = Product::factory()->create(['base_cost' => 10, 'print_method' => 'UV']);

    Sanctum::actingAs($this->superadmin);
    $response = $this->patchJson("/api/admin/products/{$product->id}", [
        'price_override' => 3.5,
    ])->assertOk();

    // 3.50 flat instead of the dynamic 16.50.
    expect($response->json('data.price_override'))->toBe('3.50')
        ->and((float) $response->json('data.selling_price'))->toBe(3.5);

    $product->refresh();
    expect((float) $product->price_override)->toBe(3.5);
    $this->assertDatabaseHas('audit_logs', ['event' => 'product.updated']);
});

it('lets a superadmin clear a price override back to dynamic pricing', function (): void {
    $product = Product::factory()->create([
        'base_cost' => 10, 'print_method' => 'UV', 'price_override' => 3.5,
    ]);

    Sanctum::actingAs($this->superadmin);
    $response = $this->patchJson("/api/admin/products/{$product->id}", [
        'price_override' => null,
    ])->assertOk();

    expect($response->json('data.price_override'))->toBeNull()
        // dynamic price restored: 10 +50% +1.50 UV = 16.50.
        ->and((float) $response->json('data.selling_price'))->toBe(16.5);

    $product->refresh();
    expect($product->price_override)->toBeNull();
});

it('ignores a price_override sent by a non-superadmin staff member', function (): void {
    $product = Product::factory()->create(['base_cost' => 10, 'print_method' => 'UV']);

    Sanctum::actingAs($this->staff);
    $response = $this->patchJson("/api/admin/products/{$product->id}", [
        'price_override' => 3.5,
        'category' => 'drinkware',
    ])->assertOk();

    // The staff edit still applies to other fields, but the override is dropped.
    expect($response->json('data.price_override'))->toBeNull()
        ->and($response->json('data.category'))->toBe('drinkware');

    $product->refresh();
    expect($product->price_override)->toBeNull();
});

it('lets a superadmin set min_order_qty, persisted and serialized', function (): void {
    $product = Product::factory()->create(['min_order_qty' => 1]);

    Sanctum::actingAs($this->superadmin);
    $response = $this->patchJson("/api/admin/products/{$product->id}", [
        'min_order_qty' => 50,
    ])->assertOk();

    expect($response->json('data.min_order_qty'))->toBe(50);

    $product->refresh();
    expect($product->min_order_qty)->toBe(50);

    // The change is audited (price_override parity): new_values records the MOQ.
    expect(
        AuditLog::query()
            ->where('event', 'product.updated')
            ->whereJsonContains('new_values->min_order_qty', 50)
            ->exists()
    )->toBeTrue();
});

it('ignores a min_order_qty sent by a non-superadmin staff member', function (): void {
    $product = Product::factory()->create(['min_order_qty' => 1]);

    Sanctum::actingAs($this->staff);
    $response = $this->patchJson("/api/admin/products/{$product->id}", [
        'min_order_qty' => 50,
    ])->assertOk();

    // The field is silently dropped for non-superadmins; the value stays 1.
    expect($response->json('data.min_order_qty'))->toBe(1);

    $product->refresh();
    expect($product->min_order_qty)->toBe(1);
});

it('blocks non-staff from image upload and removal', function (): void {
    Storage::fake('public');
    $product = Product::factory()->create();

    Sanctum::actingAs($this->buyer);
    $this->postJson("/api/admin/products/{$product->id}/image", [
        'image' => UploadedFile::fake()->image('photo.jpg'),
    ])->assertForbidden();
    $this->deleteJson("/api/admin/products/{$product->id}/image")->assertForbidden();
});

it('lets staff toggle the public 3D preview flag and exposes it publicly', function (): void {
    $product = Product::factory()->model3d()->create([
        'publish_state' => 'PUBLISHED',
        'model_file_ref' => 'models3d/x.stl',
        'model_preview_verified' => false,
    ]);

    Sanctum::actingAs($this->staff);
    $res = $this->patchJson("/api/admin/products/{$product->id}", [
        'model_preview_verified' => true,
    ])->assertOk();
    expect($res->json('data.model_preview_verified'))->toBeTrue();

    $product->refresh();
    expect($product->model_preview_verified)->toBeTrue();

    // Public catalogue resource surfaces the flag so the PDP can gate the viewer.
    $this->getJson("/api/catalogue/{$product->slug}")
        ->assertOk()
        ->assertJsonPath('data.model_preview_verified', true);
});
