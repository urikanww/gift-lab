<?php

declare(strict_types=1);

use App\Models\Product;

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
