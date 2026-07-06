<?php

declare(strict_types=1);

use App\Services\Model3d\StlDimensions;

/**
 * Build a minimal binary STL: two degenerate triangles spanning a 10 x 20 x 30
 * bounding box (geometry validity doesn't matter — only the vertex extents do).
 */
function makeBinaryStl(string $path): void
{
    $triangles = [
        [[0, 0, 0], [10, 0, 0], [0, 20, 0]],
        [[0, 0, 30], [10, 20, 30], [10, 20, 0]],
    ];

    $out = str_repeat(' ', 80).pack('V', count($triangles));
    foreach ($triangles as $tri) {
        $out .= pack('g3', 0, 0, 1); // normal
        foreach ($tri as [$x, $y, $z]) {
            $out .= pack('g3', $x, $y, $z);
        }
        $out .= pack('v', 0); // attribute byte count
    }

    file_put_contents($path, $out);
}

function makeAsciiStl(string $path): void
{
    file_put_contents($path, <<<'STL'
solid cube
facet normal 0 0 1
  outer loop
    vertex -5.0 -2.5 0.0
    vertex 5.0 -2.5 0.0
    vertex 5.0 2.5 12.5
  endloop
endfacet
endsolid cube
STL);
}

it('reads bounding-box dimensions from a binary STL', function (): void {
    $path = sys_get_temp_dir().'/stl-dims-binary-test.stl';
    makeBinaryStl($path);

    expect((new StlDimensions())->fromFile($path))
        ->toBe(['l' => 10.0, 'w' => 20.0, 'h' => 30.0, 'unit' => 'mm']);

    @unlink($path);
});

it('reads bounding-box dimensions from an ASCII STL', function (): void {
    $path = sys_get_temp_dir().'/stl-dims-ascii-test.stl';
    makeAsciiStl($path);

    expect((new StlDimensions())->fromFile($path))
        ->toBe(['l' => 10.0, 'w' => 5.0, 'h' => 12.5, 'unit' => 'mm']);

    @unlink($path);
});

it('returns null for non-STL and unreadable input', function (): void {
    $svc = new StlDimensions();

    expect($svc->fromFile(sys_get_temp_dir().'/does-not-exist.stl'))->toBeNull()
        ->and($svc->fromFile(__FILE__))->toBeNull(); // .php extension

    $tiny = sys_get_temp_dir().'/stl-dims-tiny-test.stl';
    file_put_contents($tiny, 'not an stl');
    expect($svc->fromFile($tiny))->toBeNull();
    @unlink($tiny);
});
