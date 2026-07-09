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

it('returns full-set state counts independent of pagination and the state filter', function (): void {
    Product::factory()->count(3)->model3d()->create(['publish_state' => 'PUBLISHED']);
    Product::factory()->count(2)->scrapedUv()->create(['publish_state' => 'READY_TO_APPROVE']);
    Product::factory()->model3d()->create(['publish_state' => 'CANNOT_PUBLISH']);
    Product::factory()->model3d()->create(['publish_state' => 'PENDING']);
    Product::factory()->create(['class' => 'CORE', 'publish_state' => 'PUBLISHED']); // not in the gate

    Sanctum::actingAs($this->staff);
    // A single filtered page must not shrink the summary counts.
    $res = $this->getJson('/api/admin/catalogue?per_page=2&state=PUBLISHED')->assertOk();

    $res->assertJsonPath('counts.total', 7)
        ->assertJsonPath('counts.published', 3)
        ->assertJsonPath('counts.ready', 2)
        ->assertJsonPath('counts.blocked', 1)
        ->assertJsonPath('counts.pending', 1);
    expect($res->json('data'))->toHaveCount(2); // page still limited + state-filtered
});

it('filters the gate list and counts by a name/creator search term', function (): void {
    Product::factory()->model3d()->create(['name' => 'Baby Groot Planter', 'publish_state' => 'READY_TO_APPROVE']);
    Product::factory()->model3d()->create(['name' => 'Cable Holder', 'creator_credit' => 'GrootFan', 'publish_state' => 'PUBLISHED']);
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
    Illuminate\Support\Facades\Storage::fake('local');

    $product = Product::factory()->model3d()->create([
        'publish_state' => 'CANNOT_PUBLISH',
        'cannot_publish_reasons' => ['missing_model_file'],
        'model_file_ref' => 'https://cults3d.com/thing/x',
        'license' => 'CC0',
    ]);

    Sanctum::actingAs($this->staff);
    $this->postJson("/api/admin/products/{$product->id}/model-file", [
        'file' => Illuminate\Http\UploadedFile::fake()->create('widget.stl', 12),
    ])->assertOk();

    $product->refresh();
    expect($product->model_file_ref)->toBe("models3d/manual-{$product->id}.stl")
        ->and($product->cannot_publish_reasons ?? [])->not->toContain('missing_model_file')
        ->and(Illuminate\Support\Facades\Storage::disk('local')->exists($product->model_file_ref))->toBeTrue();
});

it('rejects a non-model file upload', function (): void {
    $product = Product::factory()->model3d()->create(['license' => 'CC0']);

    Sanctum::actingAs($this->staff);
    $this->postJson("/api/admin/products/{$product->id}/model-file", [
        'file' => Illuminate\Http\UploadedFile::fake()->create('evil.php', 1),
    ])->assertStatus(422);
});

it('streams the model file for a published 3D product and hides unpublished ones', function (): void {
    Illuminate\Support\Facades\Storage::fake('local');
    Illuminate\Support\Facades\Storage::disk('local')->put('models3d/pub.stl', 'solid x');

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
    Illuminate\Support\Facades\Storage::fake('local');
    Illuminate\Support\Facades\Storage::disk('local')->put('models3d/pub.stl', 'solid x');
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
    Illuminate\Support\Facades\Storage::fake('local');
    Illuminate\Support\Facades\Storage::disk('local')->put('models3d/pub.stl', 'solid x');
    $product = Product::factory()->model3d()->create([
        'publish_state' => 'PUBLISHED',
        'model_file_ref' => 'models3d/pub.stl',
    ]);

    $first = $this->get("/api/catalogue/{$product->slug}/model")->headers->get('ETag');

    // resync --force swaps the file at the same path - the validator must change
    // so the browser refetches instead of showing the stale (wrong) geometry.
    Illuminate\Support\Facades\Storage::disk('local')->put('models3d/pub.stl', 'solid xxxxxxxxxxxxxxxxxxxx');
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
