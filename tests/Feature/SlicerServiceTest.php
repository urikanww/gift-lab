<?php

declare(strict_types=1);

use App\Models\Product;
use App\Services\Model3d\SlicerService;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    $this->slicer = app(SlicerService::class);
});

function sliceableProduct(): Product
{
    Storage::disk('local')->put('models3d/thing-1.stl', 'solid widget');

    return Product::factory()->create([
        'class' => 'MODEL_3D',
        'model_file_ref' => 'models3d/thing-1.stl',
        'est_grams' => 50,
        'estimates_verified' => false,
    ]);
}

it('is a no-op without a configured slicer binary', function (): void {
    config()->set('services.slicer.binary', '');

    expect($this->slicer->measure(sliceableProduct()))->toBeFalse();
});

it('measures grams and minutes from the sliced G-code and auto-verifies', function (): void {
    config()->set('services.slicer.binary', 'prusa-slicer');
    Process::fake(['*' => Process::result(output: 'ok')]);

    $product = sliceableProduct();
    // Process is faked, so emit the G-code the real slicer would produce.
    file_put_contents(
        Storage::disk('local')->path('models3d/thing-1.stl.gcode'),
        "G1 X0\n; filament used [g] = 82.40\n; estimated printing time (normal mode) = 3h 25m 12s\n",
    );

    expect($this->slicer->measure($product))->toBeTrue();

    $product->refresh();
    expect((float) $product->est_grams)->toBe(82.4)
        ->and((float) $product->est_print_minutes)->toBe(205.2)
        ->and((bool) $product->estimates_verified)->toBeTrue()
        ->and((bool) $product->is_printable)->toBeTrue();
});

it('flags the product not printable when slicing fails', function (): void {
    config()->set('services.slicer.binary', 'prusa-slicer');
    Process::fake(['*' => Process::result(output: '', errorOutput: 'Objects could not fit on the bed', exitCode: 1)]);

    $product = sliceableProduct();

    expect($this->slicer->measure($product))->toBeFalse();
    expect((bool) $product->refresh()->is_printable)->toBeFalse();
});
