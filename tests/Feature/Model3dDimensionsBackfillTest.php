<?php

declare(strict_types=1);

use App\Models\Product;
use Illuminate\Support\Facades\Storage;

// Audit B10: MODEL_3D dimensions derived from stored STL geometry — via the
// backfill command for pre-existing items and never overwriting set values.

beforeEach(function (): void {
    Storage::fake('local');
});

function putStlFixture(string $ref): void
{
    $triangles = [
        [[0, 0, 0], [10, 0, 0], [0, 20, 0]],
        [[0, 0, 30], [10, 20, 30], [10, 20, 0]],
    ];
    $out = str_repeat(' ', 80).pack('V', count($triangles));
    foreach ($triangles as $tri) {
        $out .= pack('g3', 0, 0, 1);
        foreach ($tri as [$x, $y, $z]) {
            $out .= pack('g3', $x, $y, $z);
        }
        $out .= pack('v', 0);
    }
    Storage::disk('local')->put($ref, $out);
}

it('backfills missing MODEL_3D dimensions from the stored STL', function (): void {
    putStlFixture('models3d/fixture-dims.stl');
    $product = Product::factory()->model3d()->create([
        'dimensions' => null,
        'model_file_ref' => 'models3d/fixture-dims.stl',
    ]);

    $this->artisan('catalogue:backfill-3d-dimensions')->assertSuccessful();

    // Whole-number floats round-trip through the JSON column as ints.
    expect($product->fresh()->dimensions)
        ->toBe(['l' => 10, 'w' => 20, 'h' => 30, 'unit' => 'mm']);
});

it('never overwrites explicitly set dimensions', function (): void {
    putStlFixture('models3d/fixture-dims-2.stl');
    $product = Product::factory()->model3d()->create([
        'dimensions' => ['l' => 1, 'w' => 2, 'h' => 3, 'unit' => 'mm'],
        'model_file_ref' => 'models3d/fixture-dims-2.stl',
    ]);

    $this->artisan('catalogue:backfill-3d-dimensions')->assertSuccessful();

    expect($product->fresh()->dimensions['l'])->toBe(1);
});

it('leaves products whose model file is missing untouched', function (): void {
    $product = Product::factory()->model3d()->create([
        'dimensions' => null,
        'model_file_ref' => 'models3d/gone.stl',
    ]);

    $this->artisan('catalogue:backfill-3d-dimensions')->assertSuccessful();

    expect($product->fresh()->dimensions)->toBeNull();
});
