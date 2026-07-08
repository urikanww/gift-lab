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
