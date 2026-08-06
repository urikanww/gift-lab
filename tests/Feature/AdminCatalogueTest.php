<?php

declare(strict_types=1);

use App\Models\PricingConfig;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

it('sorts the gate list by creation date ascending or descending', function (): void {
    $old = Product::factory()->model3d()->create(['name' => 'Older', 'publish_state' => 'READY_TO_APPROVE', 'created_at' => now()->subDays(3)]);
    $new = Product::factory()->model3d()->create(['name' => 'Newer', 'publish_state' => 'READY_TO_APPROVE', 'created_at' => now()->subDay()]);

    Sanctum::actingAs($this->staff);

    // Default (newest first) + explicit desc.
    $desc = $this->getJson('/api/admin/catalogue?sort=newest&dir=desc')->assertOk();
    expect(collect($desc->json('data'))->pluck('id')->all())->toBe([$new->id, $old->id]);

    // Ascending flips it (oldest first).
    $asc = $this->getJson('/api/admin/catalogue?sort=newest&dir=asc')->assertOk();
    expect(collect($asc->json('data'))->pluck('id')->all())->toBe([$old->id, $new->id]);
});

it('sorts the gate list by name when asked', function (): void {
    Product::factory()->model3d()->create(['name' => 'Zeta', 'publish_state' => 'READY_TO_APPROVE']);
    Product::factory()->model3d()->create(['name' => 'Alpha', 'publish_state' => 'READY_TO_APPROVE']);

    Sanctum::actingAs($this->staff);
    $res = $this->getJson('/api/admin/catalogue?sort=name&dir=asc')->assertOk();

    expect(collect($res->json('data'))->pluck('name')->all())->toBe(['Alpha', 'Zeta']);
});

it('returns full-set state counts independent of pagination and the state filter', function (): void {
    Product::factory()->count(3)->model3d()->create(['publish_state' => 'READY_TO_APPROVE']);
    Product::factory()->count(2)->scrapedUv()->create(['publish_state' => 'CANNOT_PUBLISH']);
    Product::factory()->model3d()->create(['publish_state' => 'PENDING']);
    Product::factory()->model3d()->create(['publish_state' => 'PUBLISHED']); // excluded from the gate
    Product::factory()->create(['class' => 'CORE', 'publish_state' => 'PUBLISHED']); // not in the gate

    Sanctum::actingAs($this->staff);
    // A single filtered page must not shrink the summary counts.
    $res = $this->getJson('/api/admin/catalogue?per_page=2&state=READY_TO_APPROVE')->assertOk();

    $res->assertJsonPath('counts.total', 6)
        ->assertJsonPath('counts.ready', 3)
        ->assertJsonPath('counts.blocked', 2)
        ->assertJsonPath('counts.pending', 1);
    expect($res->json('data'))->toHaveCount(2) // page still limited + state-filtered
        ->and($res->json('counts'))->not->toHaveKey('published'); // published dropped from the gate
});

it('excludes published products from the gate list and counts', function (): void {
    $published = Product::factory()->scrapedUv()->create(['publish_state' => 'PUBLISHED']);
    $unpublished = Product::factory()->scrapedUv()->create(['publish_state' => 'READY_TO_APPROVE']);

    Sanctum::actingAs($this->staff);
    $res = $this->getJson('/api/admin/catalogue')->assertOk();

    $ids = collect($res->json('data'))->pluck('id')->all();
    expect($ids)->toContain($unpublished->id)
        ->and($ids)->not->toContain($published->id)
        // The full-set counts must not count the published row, and the
        // 'published' bucket is gone entirely (the gate never shows live items).
        ->and($res->json('counts.total'))->toBe(1)
        ->and($res->json('counts'))->not->toHaveKey('published');
});

it('filters the gate list and counts by a name/creator search term', function (): void {
    Product::factory()->model3d()->create(['name' => 'Baby Groot Planter', 'publish_state' => 'READY_TO_APPROVE']);
    Product::factory()->model3d()->create(['name' => 'Cable Holder', 'creator_credit' => 'GrootFan', 'publish_state' => 'CANNOT_PUBLISH']);
    Product::factory()->scrapedUv()->create(['name' => 'Ceramic Mug', 'publish_state' => 'READY_TO_APPROVE']);

    Sanctum::actingAs($this->staff);
    // Matches the name of one item and the creator_credit of another.
    $res = $this->getJson('/api/admin/catalogue?search=groot')->assertOk();

    $names = collect($res->json('data'))->pluck('name')->all();
    expect($names)->toContain('Baby Groot Planter')
        ->and($names)->toContain('Cable Holder')
        ->and($names)->not->toContain('Ceramic Mug')
        // Counts reflect the searched subset, not the whole gate.
        ->and($res->json('counts.total'))->toBe(2);
});

it('escapes LIKE wildcards in the search term', function (): void {
    Product::factory()->model3d()->create(['name' => 'Plain Widget', 'publish_state' => 'READY_TO_APPROVE']);

    Sanctum::actingAs($this->staff);
    // A bare % must match literally (nothing), not act as a wildcard-all.
    $res = $this->getJson('/api/admin/catalogue?search=%25')->assertOk();

    expect($res->json('data'))->toHaveCount(0)
        ->and($res->json('counts.total'))->toBe(0);
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

it('verifies MODEL_3D estimates and clears the unverified hold', function (): void {
    $product = Product::factory()->model3d()->create([
        'publish_state' => 'READY_TO_APPROVE',
        'model_file_ref' => 'models3d/x.stl',
        'license' => 'CC0',
        'estimates_verified' => false,
    ]);

    Sanctum::actingAs($this->staff);
    $this->postJson("/api/admin/products/{$product->id}/verify-estimates", [
        'filament_material' => 'PETG',
        'filament_color' => 'White',
        'est_grams' => 62.5,
    ])
        ->assertOk()
        ->assertJsonPath('estimates_verified', true);

    $product->refresh();
    expect((bool) $product->estimates_verified)->toBeTrue()
        ->and((float) $product->est_grams)->toBe(62.5);
});

it('attaches a model file and clears the missing_model_file hold', function (): void {
    Storage::fake('local');

    $product = Product::factory()->model3d()->create([
        'publish_state' => 'CANNOT_PUBLISH',
        'cannot_publish_reasons' => ['missing_model_file'],
        'model_file_ref' => 'https://cults3d.com/thing/x',
        'license' => 'CC0',
    ]);

    Sanctum::actingAs($this->staff);
    $this->postJson("/api/admin/products/{$product->id}/model-file", [
        'file' => UploadedFile::fake()->create('widget.stl', 12),
    ])->assertOk();

    $product->refresh();
    expect($product->model_file_ref)->toBe("models3d/manual-{$product->id}.stl")
        ->and($product->cannot_publish_reasons ?? [])->not->toContain('missing_model_file')
        ->and(Storage::disk('local')->exists($product->model_file_ref))->toBeTrue();
});

it('rejects a non-model file upload', function (): void {
    $product = Product::factory()->model3d()->create(['license' => 'CC0']);

    Sanctum::actingAs($this->staff);
    $this->postJson("/api/admin/products/{$product->id}/model-file", [
        'file' => UploadedFile::fake()->create('evil.php', 1),
    ])->assertStatus(422);
});

it('streams the model file for a published 3D product and hides unpublished ones', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('models3d/pub.stl', 'solid x');

    $published = Product::factory()->model3d()->create([
        'publish_state' => 'PUBLISHED',
        'model_file_ref' => 'models3d/pub.stl',
    ]);
    $hidden = Product::factory()->model3d()->create([
        'publish_state' => 'READY_TO_APPROVE',
        'model_file_ref' => 'models3d/pub.stl',
    ]);

    $this->get("/api/catalogue/{$published->slug}/model")->assertOk();
    $this->get("/api/catalogue/{$hidden->slug}/model")->assertNotFound();
});

it('serves the model with a revalidating validator (304 when unchanged)', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('models3d/pub.stl', 'solid x');
    $product = Product::factory()->model3d()->create([
        'publish_state' => 'PUBLISHED',
        'model_file_ref' => 'models3d/pub.stl',
    ]);

    $res = $this->get("/api/catalogue/{$product->slug}/model");
    $res->assertOk();
    $etag = $res->headers->get('ETag');
    expect($etag)->not->toBeNull();
    expect($res->headers->get('Cache-Control'))->toContain('must-revalidate');

    // A conditional GET with the same validator must not re-send the model.
    $this->get("/api/catalogue/{$product->slug}/model", ['If-None-Match' => $etag])
        ->assertStatus(304);
});

it('changes the model validator when the stored file is replaced', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('models3d/pub.stl', 'solid x');
    $product = Product::factory()->model3d()->create([
        'publish_state' => 'PUBLISHED',
        'model_file_ref' => 'models3d/pub.stl',
    ]);

    $first = $this->get("/api/catalogue/{$product->slug}/model")->headers->get('ETag');

    // resync --force swaps the file at the same path - the validator must change
    // so the browser refetches instead of showing the stale (wrong) geometry.
    Storage::disk('local')->put('models3d/pub.stl', 'solid xxxxxxxxxxxxxxxxxxxx');
    $second = $this->get("/api/catalogue/{$product->slug}/model")->headers->get('ETag');

    expect($second)->not->toBeNull()->and($second)->not->toBe($first);
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

/** A CANNOT_PUBLISH scraped row missing everything the popup can fix. */
function blockedScrapedProduct(array $overrides = []): Product
{
    return Product::factory()->scrapedUv()->create(array_merge([
        'publish_state' => 'CANNOT_PUBLISH',
        'cannot_publish_reasons' => ['missing_price', 'missing_dimensions', 'not_printable'],
        'base_cost' => 0,
        'dimensions' => null,
        'weight' => null,
        'is_printable' => false,
        'print_method' => null,
    ], $overrides));
}

it('resolves every blocker and publishes in one call', function (): void {
    $product = blockedScrapedProduct();

    Sanctum::actingAs($this->staff);
    $this->postJson("/api/admin/products/{$product->id}/resolve-blockers", [
        'base_cost' => 12.5,
        'dimensions' => ['l' => 100, 'w' => 80, 'h' => 60],
        'weight' => 250,
        'is_printable' => true,
        'print_method' => 'UV',
    ])
        ->assertOk()
        ->assertJsonPath('published', true)
        ->assertJsonPath('cannot_publish_reasons', null);

    $product->refresh();
    expect($product->publish_state->value)->toBe('PUBLISHED')
        // decimal casts return strings
        ->and($product->weight)->toBe('250.000')
        ->and($product->dimensions)->toBe(['l' => 100, 'w' => 80, 'h' => 60, 'unit' => 'mm']);
});

it('resolves a STOCKED blank stock_estimate and publishes', function (): void {
    // A STOCKED blank with a null estimate carries stock_unreadable; typing the
    // estimate here (plus the other fixes) clears it so the row publishes.
    $product = blockedScrapedProduct(['stock_mode' => 'STOCKED', 'stock_estimate' => null]);

    Sanctum::actingAs($this->staff);
    $this->postJson("/api/admin/products/{$product->id}/resolve-blockers", [
        'base_cost' => 12.5,
        'dimensions' => ['l' => 100, 'w' => 80, 'h' => 60],
        'weight' => 250,
        'is_printable' => true,
        'print_method' => 'UV',
        'stock_estimate' => 42,
    ])
        ->assertOk()
        ->assertJsonPath('published', true)
        ->assertJsonPath('cannot_publish_reasons', null);

    $product->refresh();
    expect($product->publish_state->value)->toBe('PUBLISHED')
        ->and($product->stock_estimate)->toBe(42);
});

it('accepts a zero stock_estimate (readable, out of stock) and clears the block', function (): void {
    // 0 is a valid readable estimate - only a NULL estimate keeps stock_unreadable.
    $product = blockedScrapedProduct(['stock_mode' => 'STOCKED', 'stock_estimate' => null]);

    Sanctum::actingAs($this->staff);
    $this->postJson("/api/admin/products/{$product->id}/resolve-blockers", [
        'base_cost' => 12.5,
        'dimensions' => ['l' => 100, 'w' => 80, 'h' => 60],
        'weight' => 250,
        'is_printable' => true,
        'print_method' => 'UV',
        'stock_estimate' => 0,
    ])
        ->assertOk()
        ->assertJsonPath('published', true)
        ->assertJsonPath('cannot_publish_reasons', null);

    $product->refresh();
    expect($product->publish_state->value)->toBe('PUBLISHED')
        ->and($product->stock_estimate)->toBe(0);
});

it('saves the fix but does not publish when an unfixable blocker remains', function (): void {
    // This call fixes price/dimensions/weight but leaves the product not
    // printable (is_printable false), so not_printable keeps the row blocked -
    // yet the typed weight must still persist (work is never thrown away).
    $product = blockedScrapedProduct();

    Sanctum::actingAs($this->staff);
    $this->postJson("/api/admin/products/{$product->id}/resolve-blockers", [
        'base_cost' => 12.5,
        'dimensions' => ['l' => 100, 'w' => 80, 'h' => 60],
        'weight' => 250,
        'is_printable' => false,
        'print_method' => 'UV',
    ])
        ->assertOk()
        ->assertJsonPath('published', false)
        ->assertJsonPath('cannot_publish_reasons', ['not_printable']);

    $product->refresh();
    expect($product->publish_state->value)->toBe('CANNOT_PUBLISH')
        ->and($product->weight)->toBe('250.000'); // work was NOT thrown away
});

it('rejects a non-positive weight and writes nothing', function (): void {
    $product = blockedScrapedProduct();

    Sanctum::actingAs($this->staff);
    $this->postJson("/api/admin/products/{$product->id}/resolve-blockers", ['weight' => 0])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['weight']);

    expect($product->refresh()->weight)->toBeNull();
});

it('rejects an absurd weight above the sanity ceiling', function (): void {
    $product = blockedScrapedProduct();

    Sanctum::actingAs($this->staff);
    $this->postJson("/api/admin/products/{$product->id}/resolve-blockers", ['weight' => 500000])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['weight']);
});

it('rejects an absurd dimension above the sanity ceiling', function (): void {
    $product = blockedScrapedProduct();

    Sanctum::actingAs($this->staff);
    $this->postJson("/api/admin/products/{$product->id}/resolve-blockers", [
        'dimensions' => ['l' => 5000, 'w' => 80, 'h' => 60],
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['dimensions.l']);
});

it('rejects an unknown print method', function (): void {
    $product = blockedScrapedProduct();

    Sanctum::actingAs($this->staff);
    $this->postJson("/api/admin/products/{$product->id}/resolve-blockers", ['print_method' => 'LASER'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['print_method']);
});

it('requires every dimension when dimensions are sent at all', function (): void {
    $product = blockedScrapedProduct();

    Sanctum::actingAs($this->staff);
    $this->postJson("/api/admin/products/{$product->id}/resolve-blockers", [
        'dimensions' => ['l' => 100],
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['dimensions.w', 'dimensions.h']);
});

it('refuses to resolve blockers on a MODEL_3D product', function (): void {
    $product = Product::factory()->model3d()->create(['publish_state' => 'CANNOT_PUBLISH']);

    Sanctum::actingAs($this->staff);
    $this->postJson("/api/admin/products/{$product->id}/resolve-blockers", ['weight' => 250])
        ->assertStatus(422);
});

it('refuses to resolve blockers on an already-published product', function (): void {
    $product = Product::factory()->scrapedUv()->create(['publish_state' => 'PUBLISHED']);

    Sanctum::actingAs($this->staff);
    $this->postJson("/api/admin/products/{$product->id}/resolve-blockers", ['weight' => 250])
        ->assertStatus(422);
});

it('forbids a non-staff user from resolving blockers', function (): void {
    $product = blockedScrapedProduct();

    Sanctum::actingAs(User::factory()->create()); // buyer
    $this->postJson("/api/admin/products/{$product->id}/resolve-blockers", ['weight' => 250])
        ->assertStatus(403);
});

it('audit-logs a blocker resolution', function (): void {
    $product = blockedScrapedProduct();

    Sanctum::actingAs($this->staff);
    $this->postJson("/api/admin/products/{$product->id}/resolve-blockers", [
        'base_cost' => 12.5,
        'dimensions' => ['l' => 100, 'w' => 80, 'h' => 60],
        'weight' => 250,
        'is_printable' => true,
        'print_method' => 'UV',
    ])->assertOk();

    $this->assertDatabaseHas('audit_logs', [
        'auditable_type' => Product::class,
        'auditable_id' => $product->id,
        'event' => 'product.blockers_resolved',
        'user_id' => $this->staff->id,
    ]);
});

it('returns the blocker-prefill fields on each gate row', function (): void {
    Product::factory()->scrapedUv()->create([
        'publish_state' => 'CANNOT_PUBLISH',
        'weight' => 250,
        'dimensions' => ['l' => 100, 'w' => 80, 'h' => 60, 'unit' => 'mm'],
        'print_method' => 'UV',
        'is_printable' => true,
    ]);

    Sanctum::actingAs($this->staff);
    $res = $this->getJson('/api/admin/catalogue')->assertOk();

    $res->assertJsonPath('data.0.weight', '250.000')
        ->assertJsonPath('data.0.dimensions.l', 100)
        ->assertJsonPath('data.0.print_method', 'UV')
        ->assertJsonPath('data.0.is_printable', true);
});

it('returns stock_estimate on each gate row', function (): void {
    Product::factory()->scrapedUv()->create([
        'publish_state' => 'CANNOT_PUBLISH',
        'stock_mode' => 'STOCKED',
        'stock_estimate' => 15,
    ]);

    Sanctum::actingAs($this->staff);
    $res = $this->getJson('/api/admin/catalogue')->assertOk();

    $res->assertJsonPath('data.0.stock_estimate', 15);
});

it('soft-deletes an unpublished gate item and drops it from the list', function (): void {
    $product = Product::factory()->scrapedUv()->create(['publish_state' => 'CANNOT_PUBLISH']);

    Sanctum::actingAs($this->staff);
    $this->deleteJson("/api/admin/catalogue/{$product->id}")
        ->assertOk()
        ->assertJsonPath('deleted', true);

    expect($product->fresh()->trashed())->toBeTrue();

    // Gone from the gate list.
    $res = $this->getJson('/api/admin/catalogue')->assertOk();
    expect(collect($res->json('data'))->pluck('id')->all())->not->toContain($product->id);

    // Audited under the gate-delete event.
    $this->assertDatabaseHas('audit_logs', [
        'auditable_type' => Product::class,
        'auditable_id' => $product->id,
        'event' => 'product.gate_deleted',
        'user_id' => $this->staff->id,
    ]);
});

it('refuses to delete a non-gate (CORE) product and skips it in bulk', function (): void {
    $core = Product::factory()->create(['class' => 'CORE', 'publish_state' => 'READY_TO_APPROVE']);
    $gate = Product::factory()->scrapedUv()->create(['publish_state' => 'CANNOT_PUBLISH']);

    Sanctum::actingAs($this->staff);

    // Single delete of a CORE row is refused (it isn't a gate item).
    $this->deleteJson("/api/admin/catalogue/{$core->id}")
        ->assertStatus(422)
        ->assertJsonPath('message', "This product isn't managed in the catalogue gate.");
    expect($core->fresh()->trashed())->toBeFalse();

    // In bulk, a CORE id is skipped like a published one; the gate row still deletes.
    $res = $this->postJson('/api/admin/catalogue/bulk-delete', [
        'ids' => [$core->id, $gate->id],
    ])->assertOk();

    expect($res->json('deleted'))->toBe([$gate->id])
        ->and($res->json('skipped'))->toBe([$core->id]);
    expect($core->fresh()->trashed())->toBeFalse()
        ->and($gate->fresh()->trashed())->toBeTrue();
});

it('refuses to delete a published product (422) and leaves it intact', function (): void {
    $product = Product::factory()->scrapedUv()->create(['publish_state' => 'PUBLISHED']);

    Sanctum::actingAs($this->staff);
    $this->deleteJson("/api/admin/catalogue/{$product->id}")
        ->assertStatus(422)
        ->assertJsonPath('message', 'Unpublish a live product before deleting it.');

    expect($product->fresh()->trashed())->toBeFalse();
});

it('bulk-deletes unpublished rows and skips published ones', function (): void {
    $unpublishedA = Product::factory()->scrapedUv()->create(['publish_state' => 'CANNOT_PUBLISH']);
    $unpublishedB = Product::factory()->model3d()->create(['publish_state' => 'READY_TO_APPROVE']);
    $published = Product::factory()->scrapedUv()->create(['publish_state' => 'PUBLISHED']);

    Sanctum::actingAs($this->staff);
    $res = $this->postJson('/api/admin/catalogue/bulk-delete', [
        'ids' => [$unpublishedA->id, $unpublishedB->id, $published->id],
    ])->assertOk();

    expect($res->json('deleted'))->toContain($unpublishedA->id)->toContain($unpublishedB->id)
        ->and($res->json('deleted'))->not->toContain($published->id)
        ->and($res->json('skipped'))->toBe([$published->id]);

    expect($unpublishedA->fresh()->trashed())->toBeTrue()
        ->and($unpublishedB->fresh()->trashed())->toBeTrue()
        ->and($published->fresh()->trashed())->toBeFalse();

    $this->assertDatabaseHas('audit_logs', [
        'auditable_type' => Product::class,
        'auditable_id' => $unpublishedA->id,
        'event' => 'product.gate_deleted',
    ]);
});

it('forbids a viewer-only staff user from deleting or bulk-deleting', function (): void {
    $viewer = User::factory()->staffAdmin()->create(['permissions' => ['products.view']]);
    $product = Product::factory()->scrapedUv()->create(['publish_state' => 'CANNOT_PUBLISH']);

    Sanctum::actingAs($viewer);
    $this->deleteJson("/api/admin/catalogue/{$product->id}")->assertForbidden();
    $this->postJson('/api/admin/catalogue/bulk-delete', ['ids' => [$product->id]])->assertForbidden();

    expect($product->fresh()->trashed())->toBeFalse();
});
