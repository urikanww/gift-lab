<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

it('persists print_zone as an array and decor_glb_ref', function (): void {
    $zone = [
        'normal' => [0.0, 0.0, 1.0],
        'center' => [0.0, 0.0, 5.0],
        'up' => [0.0, 1.0, 0.0],
        'width_mm' => 40.0,
        'height_mm' => 30.0,
    ];

    $p = Product::factory()->create([
        'class' => 'MODEL_3D',
        'print_zone' => $zone,
        'decor_glb_ref' => 'models3d/decor-1.glb',
    ]);

    $fresh = $p->fresh();
    // toEqual (non-strict): PHP's json_encode drops the fractional .0 from
    // whole-number floats (json_encode(1.0) === "1"), so round-tripping
    // axis-aligned values like `1.0` through the `array` cast decodes them
    // back as int 1. This is a PHP/JSON quirk, not a data-loss bug - the
    // wire format (and JS, which has no int/float distinction) is unaffected.
    expect($fresh->print_zone)->toEqual($zone)
        ->and($fresh->decor_glb_ref)->toBe('models3d/decor-1.glb');
});

it('streams a model file to staff regardless of publish state', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('models3d/manual-7.stl', 'solid x');

    $product = Product::factory()->create([
        'class' => 'MODEL_3D',
        'publish_state' => 'PENDING',
        'model_file_ref' => 'models3d/manual-7.stl',
    ]);

    $staff = User::factory()->staffAdmin()->create();

    Sanctum::actingAs($staff);

    $this->get("/api/admin/products/{$product->id}/model?kind=mesh")
        ->assertOk()
        ->assertHeader('content-type', 'application/octet-stream');
});

it('forbids the staff model stream to non-staff', function (): void {
    $product = Product::factory()->create(['class' => 'MODEL_3D']);
    $buyer = User::factory()->create(['role' => 'buyer']);

    Sanctum::actingAs($buyer);

    $this->get("/api/admin/products/{$product->id}/model?kind=mesh")
        ->assertForbidden();
});

it('deletes the orphaned old mesh when the extension changes', function (): void {
    Illuminate\Support\Facades\Storage::fake('local');
    $disk = Illuminate\Support\Facades\Storage::disk('local');
    $disk->put('models3d/manual-9.stl', 'old');

    $product = Product::factory()->create([
        'class' => 'MODEL_3D',
        'model_file_ref' => 'models3d/manual-9.stl',
        'print_zone' => ['normal' => [0, 0, 1], 'center' => [0, 0, 0], 'up' => [0, 1, 0], 'width_mm' => 10, 'height_mm' => 10],
    ]);
    $staff = App\Models\User::factory()->staffAdmin()->create();
    Laravel\Sanctum\Sanctum::actingAs($staff);

    $file = Illuminate\Http\UploadedFile::fake()->create('part.obj', 4, 'text/plain');
    $this->post("/api/admin/products/{$product->id}/model-file", ['file' => $file])
        ->assertOk();

    // The stored filename is manual-{id}.{ext}; assert the OLD one is gone and the new exists.
    expect($disk->exists('models3d/manual-9.stl'))->toBeFalse();
    expect($disk->exists("models3d/manual-{$product->id}.obj"))->toBeTrue();
    expect($product->fresh()->print_zone)->toBeNull(); // mesh replace invalidates the zone
});

it('stores an uploaded glb into decor_glb_ref and keeps the mesh + zone', function (): void {
    Illuminate\Support\Facades\Storage::fake('local');
    $zone = ['normal' => [0, 0, 1], 'center' => [0, 0, 0], 'up' => [0, 1, 0], 'width_mm' => 10, 'height_mm' => 10];
    $product = Product::factory()->create([
        'class' => 'MODEL_3D',
        'model_file_ref' => 'models3d/manual-11.stl',
        'print_zone' => $zone,
    ]);
    $staff = App\Models\User::factory()->staffAdmin()->create();
    Laravel\Sanctum\Sanctum::actingAs($staff);

    $file = Illuminate\Http\UploadedFile::fake()->create('decor.glb', 8, 'model/gltf-binary');
    $this->post("/api/admin/products/{$product->id}/model-file", ['file' => $file])
        ->assertOk();

    $fresh = $product->fresh();
    expect($fresh->decor_glb_ref)->toBe("models3d/decor-{$product->id}.glb");
    expect($fresh->model_file_ref)->toBe('models3d/manual-11.stl'); // unchanged
    expect($fresh->print_zone)->toEqual($zone); // GLB is display-only; zone kept
});

it('saves a print zone for a MODEL_3D product', function (): void {
    $product = Product::factory()->create(['class' => 'MODEL_3D']);
    $staff = App\Models\User::factory()->staffAdmin()->create();
    Laravel\Sanctum\Sanctum::actingAs($staff);
    $zone = ['normal' => [0, 0, 1], 'center' => [1, 2, 3], 'up' => [0, 1, 0], 'width_mm' => 42.5, 'height_mm' => 20];

    $this->postJson("/api/admin/products/{$product->id}/print-zone", ['print_zone' => $zone])
        ->assertOk();

    expect($product->fresh()->print_zone)->toEqual($zone);
});

it('rejects an unsupported model extension', function (): void {
    $product = Product::factory()->create(['class' => 'MODEL_3D', 'model_file_ref' => 'models3d/manual-x.stl']);
    $staff = App\Models\User::factory()->staffAdmin()->create();
    Laravel\Sanctum\Sanctum::actingAs($staff);
    $file = Illuminate\Http\UploadedFile::fake()->create('bad.txt', 2, 'text/plain');
    $this->post("/api/admin/products/{$product->id}/model-file", ['file' => $file])
        ->assertStatus(422);
});
