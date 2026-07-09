<?php

declare(strict_types=1);

namespace App\Services\Model3d;

/**
 * Merge several STL files (binary or ASCII) into one binary STL.
 *
 * Many source models are multi-part prints split across separate STLs (e.g. a
 * figure's head, body and base). The ingest previously stored only the FIRST
 * file, so the printed/previewed geometry was incomplete (the "Baby Groot head
 * only" bug). Concatenating every part's triangles into a single binary STL
 * restores the full geometry and a correct bounding box.
 *
 * Note: parts are typically laid out for printing, not pre-assembled, so the
 * merged mesh contains all geometry but is not guaranteed to be posed as the
 * assembled figure - it never drops parts, which is the point.
 */
final class StlMerger
{
    /**
     * @param  list<string>  $contents  raw STL file contents (binary or ASCII)
     * @return string|null  binary STL bytes, or null when no triangles were read
     */
    public function mergeToBinary(array $contents): ?string
    {
        $records = '';
        $count = 0;

        foreach ($contents as $content) {
            [$recs, $n] = $this->triangleRecords($content);
            $records .= $recs;
            $count += $n;
        }

        if ($count === 0) {
            return null;
        }

        return str_repeat("\0", 80).pack('V', $count).$records;
    }

    /**
     * Extract 50-byte binary triangle records from one STL (binary or ASCII).
     *
     * @return array{0: string, 1: int}  [records, triangleCount]
     */
    private function triangleRecords(string $content): array
    {
        $len = strlen($content);

        // Binary STL is exactly 84 + 50·count bytes; anything else is ASCII.
        if ($len >= 84) {
            $count = (int) unpack('V', substr($content, 80, 4))[1];
            if ($count > 0 && $len === 84 + $count * 50) {
                return [substr($content, 84, $count * 50), $count];
            }
        }

        return $this->asciiRecords($content);
    }

    /**
     * Parse ASCII STL vertices into binary triangle records. Normals are zeroed
     * (slicers recompute them); attribute byte count is zero.
     *
     * @return array{0: string, 1: int}
     */
    private function asciiRecords(string $content): array
    {
        $records = '';
        $count = 0;
        $verts = [];

        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            if (preg_match('/^\s*vertex\s+([-\d.eE+]+)\s+([-\d.eE+]+)\s+([-\d.eE+]+)/', $line, $m) !== 1) {
                continue;
            }
            $verts[] = [(float) $m[1], (float) $m[2], (float) $m[3]];

            if (count($verts) === 3) {
                $records .= pack('g3', 0, 0, 0); // normal
                foreach ($verts as $v) {
                    $records .= pack('g3', $v[0], $v[1], $v[2]);
                }
                $records .= "\0\0"; // attribute byte count
                $count++;
                $verts = [];
            }
        }

        return [$records, $count];
    }
}
