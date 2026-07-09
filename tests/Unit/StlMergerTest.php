<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Model3d\StlDimensions;
use App\Services\Model3d\StlMerger;
use PHPUnit\Framework\TestCase;

class StlMergerTest extends TestCase
{
    /**
     * Build a binary STL from triangles (each triangle = 3 vertices [x,y,z]).
     *
     * @param  list<list<array{0: float, 1: float, 2: float}>>  $triangles
     */
    private function binaryStl(array $triangles): string
    {
        $body = '';
        foreach ($triangles as $tri) {
            $body .= pack('g3', 0, 0, 0); // normal (recomputed by slicers)
            foreach ($tri as $v) {
                $body .= pack('g3', $v[0], $v[1], $v[2]);
            }
            $body .= "\0\0"; // attribute byte count
        }

        return str_repeat("\0", 80).pack('V', count($triangles)).$body;
    }

    private function triangleCount(string $binaryStl): int
    {
        return (int) unpack('V', substr($binaryStl, 80, 4))[1];
    }

    public function test_merges_two_binary_stls_into_one_with_all_triangles(): void
    {
        $a = $this->binaryStl([[[0, 0, 0], [1, 0, 0], [0, 1, 0]]]);
        $b = $this->binaryStl([[[0, 0, 0], [0, 0, 5], [5, 0, 0]]]);

        $merged = (new StlMerger)->mergeToBinary([$a, $b]);

        $this->assertNotNull($merged);
        $this->assertSame(2, $this->triangleCount($merged));
    }

    public function test_merged_bounding_box_covers_all_parts(): void
    {
        // Part A spans x 0..1; part B spans x 0..5 and z 0..5. A lone part (the
        // "Baby Groot head only" bug) would under-report the footprint; the
        // merge must yield the combined bounding box.
        $a = $this->binaryStl([[[0, 0, 0], [1, 0, 0], [0, 1, 0]]]);
        $b = $this->binaryStl([[[0, 0, 0], [0, 0, 5], [5, 0, 0]]]);

        $merged = (new StlMerger)->mergeToBinary([$a, $b]);

        $tmp = tempnam(sys_get_temp_dir(), 'stl').'.stl';
        file_put_contents($tmp, $merged);
        try {
            $dims = (new StlDimensions)->fromFile($tmp);
        } finally {
            @unlink($tmp);
        }

        $this->assertNotNull($dims);
        $this->assertSame(5.0, $dims['l']); // x
        $this->assertSame(1.0, $dims['w']); // y
        $this->assertSame(5.0, $dims['h']); // z
    }

    public function test_parses_ascii_stl_input(): void
    {
        $ascii = "solid part\n"
            ."facet normal 0 0 0\n"
            ."outer loop\n"
            ."vertex 0 0 0\n"
            ."vertex 2 0 0\n"
            ."vertex 0 3 0\n"
            ."endloop\n"
            ."endfacet\n"
            ."endsolid part\n";

        $merged = (new StlMerger)->mergeToBinary([$ascii]);

        $this->assertNotNull($merged);
        $this->assertSame(1, $this->triangleCount($merged));
    }

    public function test_returns_null_when_no_triangles_readable(): void
    {
        $this->assertNull((new StlMerger)->mergeToBinary(['not an stl at all']));
        $this->assertNull((new StlMerger)->mergeToBinary([]));
    }

    public function test_counts_triangles_of_binary_and_ascii(): void
    {
        $binary = $this->binaryStl([
            [[0, 0, 0], [1, 0, 0], [0, 1, 0]],
            [[0, 0, 0], [0, 0, 1], [1, 0, 0]],
        ]);
        $ascii = "solid p\nfacet normal 0 0 0\nouter loop\n"
            ."vertex 0 0 0\nvertex 1 0 0\nvertex 0 1 0\n"
            ."endloop\nendfacet\nendsolid p\n";

        $merger = new StlMerger;
        $this->assertSame(2, $merger->triangleCount($binary));
        $this->assertSame(1, $merger->triangleCount($ascii));
        $this->assertSame(0, $merger->triangleCount('garbage'));
    }
}
