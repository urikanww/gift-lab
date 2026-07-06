<?php

declare(strict_types=1);

namespace App\Services\Model3d;

/**
 * Bounding-box dimensions straight from an STL file's geometry (audit B10):
 * MODEL_3D items ingested from source APIs carry no physical dimensions, but
 * the printable file we already store knows them exactly. STL convention is
 * millimetres. Binary and ASCII variants supported; 3MF/OBJ return null (a
 * slicer integration is the right tool for those).
 */
final class StlDimensions
{
    /** Triangles read per chunk when scanning a binary STL. */
    private const CHUNK_TRIANGLES = 4096;

    /**
     * @return array{l: float, w: float, h: float, unit: string}|null
     */
    public function fromFile(string $absolutePath): ?array
    {
        if (! is_file($absolutePath) || ! str_ends_with(strtolower($absolutePath), '.stl')) {
            return null;
        }

        $size = (int) filesize($absolutePath);
        if ($size < 84) {
            return null;
        }

        $handle = fopen($absolutePath, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            fread($handle, 80); // header
            $countRaw = fread($handle, 4);
            if ($countRaw === false || strlen($countRaw) < 4) {
                return null;
            }
            $count = (int) unpack('V', $countRaw)[1];

            // A well-formed binary STL is exactly 84 + 50·count bytes; anything
            // else is treated as ASCII ("solid ... facet normal ... vertex").
            if ($size === 84 + $count * 50 && $count > 0) {
                return $this->binaryBounds($handle, $count);
            }

            rewind($handle);

            return $this->asciiBounds($handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle  positioned just past the 84-byte header
     * @return array{l: float, w: float, h: float, unit: string}|null
     */
    private function binaryBounds($handle, int $count): ?array
    {
        $min = [INF, INF, INF];
        $max = [-INF, -INF, -INF];
        $remaining = $count;

        while ($remaining > 0) {
            $batch = min($remaining, self::CHUNK_TRIANGLES);
            $bytes = fread($handle, $batch * 50);
            if ($bytes === false || strlen($bytes) < $batch * 50) {
                return null;
            }

            for ($t = 0; $t < $batch; $t++) {
                // Triangle record: 12 bytes normal, 3 × 12-byte vertices, 2-byte attr.
                $base = $t * 50 + 12;
                /** @var array<int, float> $floats */
                $floats = array_values(unpack('g9', substr($bytes, $base, 36)));
                for ($v = 0; $v < 9; $v++) {
                    $axis = $v % 3;
                    $value = $floats[$v];
                    if ($value < $min[$axis]) {
                        $min[$axis] = $value;
                    }
                    if ($value > $max[$axis]) {
                        $max[$axis] = $value;
                    }
                }
            }

            $remaining -= $batch;
        }

        return $this->toDimensions($min, $max);
    }

    /**
     * @param  resource  $handle
     * @return array{l: float, w: float, h: float, unit: string}|null
     */
    private function asciiBounds($handle): ?array
    {
        $min = [INF, INF, INF];
        $max = [-INF, -INF, -INF];
        $sawVertex = false;

        while (($line = fgets($handle)) !== false) {
            if (preg_match('/^\s*vertex\s+([-\d.eE+]+)\s+([-\d.eE+]+)\s+([-\d.eE+]+)/', $line, $m) !== 1) {
                continue;
            }
            $sawVertex = true;
            for ($axis = 0; $axis < 3; $axis++) {
                $value = (float) $m[$axis + 1];
                if ($value < $min[$axis]) {
                    $min[$axis] = $value;
                }
                if ($value > $max[$axis]) {
                    $max[$axis] = $value;
                }
            }
        }

        return $sawVertex ? $this->toDimensions($min, $max) : null;
    }

    /**
     * @param  array<int, float>  $min
     * @param  array<int, float>  $max
     * @return array{l: float, w: float, h: float, unit: string}|null
     */
    private function toDimensions(array $min, array $max): ?array
    {
        $l = $max[0] - $min[0];
        $w = $max[1] - $min[1];
        $h = $max[2] - $min[2];

        if (! is_finite($l) || ! is_finite($w) || ! is_finite($h) || $l <= 0 || $w <= 0 || $h <= 0) {
            return null;
        }

        return [
            'l' => round($l, 1),
            'w' => round($w, 1),
            'h' => round($h, 1),
            'unit' => 'mm',
        ];
    }
}
