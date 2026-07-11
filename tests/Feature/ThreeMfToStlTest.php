<?php

declare(strict_types=1);

use App\Services\Model3d\ThreeMfConversionException;
use App\Services\Model3d\ThreeMfToStl;

// Phase 2: the .3mf -> binary STL converter. MakerWorld ships Bambu `.3mf`
// projects, but our floor + previewer speak STL. These tests convert the real
// scraped sample projects and assert the output is a well-formed binary STL of
// non-empty, sanely-bounded, assembled geometry.

/**
 * Parse a binary STL's declared triangle count and world-space bounding box.
 *
 * @return array{count: int, min: array<int, float>, max: array<int, float>}
 */
function stlDigest(string $stl): array
{
    $count = (int) unpack('V', substr($stl, 80, 4))[1];

    $min = [INF, INF, INF];
    $max = [-INF, -INF, -INF];
    for ($i = 0; $i < $count; $i++) {
        // Skip the 12-byte normal; read the three vertices (9 floats).
        $floats = array_values(unpack('g9', substr($stl, 84 + $i * 50 + 12, 36)));
        for ($v = 0; $v < 9; $v++) {
            $axis = $v % 3;
            $val = $floats[$v];
            if ($val < $min[$axis]) {
                $min[$axis] = $val;
            }
            if ($val > $max[$axis]) {
                $max[$axis] = $val;
            }
        }
    }

    return ['count' => $count, 'min' => $min, 'max' => $max];
}

function sampleThreeMf(string $file): string
{
    $path = base_path('scraper/out/models3d/'.$file);
    expect(is_file($path))->toBeTrue("sample .3mf missing: {$file}");

    return (string) file_get_contents($path);
}

it('converts a single-object .3mf into a valid, sanely-bounded binary STL', function (): void {
    $stl = (new ThreeMfToStl)->convert(
        sampleThreeMf('12-in-1-ultimate-multi-fidget-toy-print-in-place-3012887.3mf')
    );

    $digest = stlDigest($stl);

    // Well-formed binary STL container: 80-byte header + uint32 count + records,
    // and the declared count must match the actual byte length exactly.
    expect($stl)->toBeString()
        ->and(strlen($stl))->toBeGreaterThanOrEqual(84)
        ->and($digest['count'])->toBeGreaterThan(0)
        ->and(strlen($stl))->toBe(84 + $digest['count'] * 50);

    // The 80-byte header is our zero-filled framing (mirrors StlMerger).
    expect(substr($stl, 0, 80))->toBe(str_repeat("\0", 80));

    // A sane, finite, non-degenerate bounding box (real geometry, not a point).
    foreach ([0, 1, 2] as $axis) {
        $extent = $digest['max'][$axis] - $digest['min'][$axis];
        expect(is_finite($extent))->toBeTrue()
            ->and($extent)->toBeGreaterThan(0.0);
    }
});

it('assembles a 100+ object multi-part .3mf without error (the Imperial Shuttle)', function (): void {
    // The hard case: 100+ objects whose meshes live in separate model parts,
    // each placed by a per-item transform. This must resolve every part and
    // produce a valid, non-empty STL - proving the recursive component +
    // cross-part transform handling holds up at scale.
    $stl = (new ThreeMfToStl)->convert(
        sampleThreeMf('--imperial-shuttle-3009900.3mf')
    );

    $digest = stlDigest($stl);

    expect($digest['count'])->toBeGreaterThan(0)
        ->and(strlen($stl))->toBe(84 + $digest['count'] * 50);

    foreach ([0, 1, 2] as $axis) {
        $extent = $digest['max'][$axis] - $digest['min'][$axis];
        expect(is_finite($extent))->toBeTrue()
            ->and($extent)->toBeGreaterThan(0.0);
    }
});

it('throws a typed exception when the bytes are not a valid .3mf archive', function (): void {
    (new ThreeMfToStl)->convert('this is definitely not a zip archive');
})->throws(ThreeMfConversionException::class);
