<?php

declare(strict_types=1);

namespace App\Services\Model3d;

use Throwable;
use XMLReader;
use ZipArchive;

/**
 * Convert a Bambu/3MF project into a single binary STL of the *assembled*
 * geometry.
 *
 * WHY this exists: our production floor and the browser previewer (StlModelViewer,
 * StlDimensions) speak STL, but MakerWorld ships Bambu Studio `.3mf` projects.
 * A `.3mf` is a ZIP whose real geometry lives in an XML part; a multi-object
 * Bambu project (the Imperial Shuttle has 100+ parts) references per-part mesh
 * files and positions each on the plate via a per-item transform. Naively
 * grabbing one mesh gives you a single stray part in the wrong place (the same
 * class of bug {@see StlMerger} fixed for split STLs). This service walks the
 * `<build>`, resolves every referenced object - recursing through
 * `<components>` that point at other objects, possibly in other model parts -
 * composes the transforms, and bakes every triangle into world space.
 *
 * WHY XMLReader and not SimpleXML: these files reach 100 MB+ of XML. SimpleXML/DOM
 * would materialise the whole tree (many GB of PHP objects) before we read a
 * single vertex. We stream with {@see XMLReader} instead, holding at most one
 * part's vertex table in memory at a time and appending triangles straight to
 * the output buffer - the same "read a chunk, keep only what you need" posture
 * as {@see StlDimensions}.
 *
 * The binary STL assembly (80-byte header, uint32 count, 50-byte little-endian
 * `g`-packed records) mirrors {@see StlMerger::mergeToBinary()} so both writers
 * emit byte-identical container framing.
 */
final class ThreeMfToStl
{
    /**
     * Hard ceiling on emitted triangles. A malicious or pathological project
     * could otherwise pin memory/CPU (each triangle is 50 output bytes, so 20M
     * ≈ 1 GB). Breaching it throws {@see ThreeMfConversionException} so the
     * caller falls back rather than OOMs. 20M comfortably covers real prints -
     * even dense multi-part figures land in the low millions.
     */
    private const MAX_TRIANGLES = 20_000_000;

    /** Guard against cyclic component references (a malformed file could loop). */
    private const MAX_COMPONENT_DEPTH = 256;

    /** 3MF "production" extension namespace - carries `p:path` on components. */
    private const PRODUCTION_NS = 'http://schemas.microsoft.com/3dmanufacturing/production/2015/06';

    /**
     * Extracted model parts, keyed by their in-zip entry name. We copy each
     * referenced `*.model` out of the archive to a temp file (streamed, never
     * fully buffered) so XMLReader can open it by path and re-scan it cheaply
     * for each object we resolve. Cleaned up in {@see convert()}'s finally.
     *
     * @var array<string, string> entryName => absolute temp path
     */
    private array $partFiles = [];

    /**
     * Lightweight object graph per model part: object id => structure. We record
     * only whether an object is a mesh or a list of component references - never
     * the geometry - so this stays tiny even for huge parts. Geometry is streamed
     * on demand in {@see emitObject()}.
     *
     * @var array<string, array<string, array{mesh: bool, components: list<array{0: ?string, 1: string, 2: list<float>}>}>>
     */
    private array $graphs = [];

    private ZipArchive $zip;

    /**
     * Convert raw `.3mf` bytes to binary STL bytes (the assembled, world-space
     * mesh). Throws {@see ThreeMfConversionException} on any structural failure
     * or if the model exceeds {@see self::MAX_TRIANGLES}.
     */
    public function convert(string $threeMfBytes): string
    {
        // Reset per-call state so one instance can convert many files safely.
        $this->partFiles = [];
        $this->graphs = [];

        $zipPath = tempnam(sys_get_temp_dir(), '3mf');
        if ($zipPath === false) {
            throw new ThreeMfConversionException('Could not allocate a temp file for the .3mf archive.');
        }
        if (file_put_contents($zipPath, $threeMfBytes) === false) {
            @unlink($zipPath);
            throw new ThreeMfConversionException('Could not write the .3mf bytes to disk for unzipping.');
        }

        $this->zip = new ZipArchive;
        if ($this->zip->open($zipPath) !== true) {
            @unlink($zipPath);
            throw new ThreeMfConversionException('The supplied bytes are not a readable ZIP/.3mf archive.');
        }

        try {
            $rootPart = $this->rootModelPart();

            $records = '';
            $count = 0;

            // Walk the build plate: each item drops an object onto the plate with
            // a placement transform. Resolve it (recursing through components) and
            // accumulate its world-space triangles.
            foreach ($this->buildItems($rootPart) as [$objectId, $transform]) {
                $this->emitObject($rootPart, $objectId, $transform, 0, $records, $count);
            }

            if ($count === 0) {
                throw new ThreeMfConversionException('The .3mf produced no triangles (no resolvable build geometry).');
            }

            return str_repeat("\0", 80).pack('V', $count).$records;
        } catch (ThreeMfConversionException $e) {
            throw $e;
        } catch (Throwable $e) {
            // Normalise any lower-level parse/IO error into the typed exception
            // so callers only have one failure mode to catch.
            throw new ThreeMfConversionException('Failed to convert .3mf to STL: '.$e->getMessage(), 0, $e);
        } finally {
            $this->zip->close();
            foreach ($this->partFiles as $tmp) {
                @unlink($tmp);
            }
            @unlink($zipPath);
        }
    }

    /**
     * Locate the primary model part. Per OPC/3MF it is named by the start-part
     * relationship in `_rels/.rels`; we read that when present and otherwise
     * fall back to the conventional `3D/3dmodel.model` that every Bambu export
     * uses.
     */
    private function rootModelPart(): string
    {
        $rels = $this->zip->getFromName('_rels/.rels');
        if (is_string($rels) && $rels !== '') {
            // The 3dmodel relationship Type ends in ".../3dmodel"; grab its Target.
            if (preg_match('/<Relationship\b[^>]*Type="[^"]*\/3dmodel"[^>]*Target="([^"]+)"/i', $rels, $m) === 1
                || preg_match('/<Relationship\b[^>]*Target="([^"]+)"[^>]*Type="[^"]*\/3dmodel"/i', $rels, $m) === 1) {
                return ltrim($m[1], '/');
            }
        }

        return '3D/3dmodel.model';
    }

    /**
     * Read the `<build>` items of a model part: [objectId, transform] pairs.
     * Streamed - we stop caring about the file the moment `</build>` closes.
     *
     * @return list<array{0: string, 1: list<float>}>
     */
    private function buildItems(string $entry): array
    {
        $reader = $this->openPart($entry);
        $items = [];

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'item') {
                    continue;
                }
                $objectId = $reader->getAttribute('objectid');
                if ($objectId === null) {
                    continue;
                }
                $items[] = [$objectId, $this->parseTransform($reader->getAttribute('transform'))];
            }
        } finally {
            $reader->close();
        }

        return $items;
    }

    /**
     * Resolve one object in a part to world-space triangles, appending 50-byte
     * binary STL records to $records and bumping $count.
     *
     * An object is either a mesh (emit its triangles under the accumulated
     * transform) or a set of components (recurse into each, composing its
     * transform onto ours). Components may point into another model part via
     * `p:path`, which is how Bambu splits a 100-part project across files.
     */
    private function emitObject(string $entry, string $objectId, array $transform, int $depth, string &$records, int &$count): void
    {
        if ($depth > self::MAX_COMPONENT_DEPTH) {
            throw new ThreeMfConversionException('Component nesting too deep (cyclic reference?) in '.$entry);
        }

        $object = $this->graph($entry)[$objectId] ?? null;
        if ($object === null) {
            // A dangling reference: skip it rather than abort - a single missing
            // sub-part shouldn't sink an otherwise-good 100-part model.
            return;
        }

        if ($object['mesh']) {
            $this->emitMesh($entry, $objectId, $transform, $records, $count);

            return;
        }

        foreach ($object['components'] as [$path, $childId, $childTransform]) {
            $childEntry = $path !== null ? ltrim($path, '/') : $entry;
            $this->emitObject(
                $childEntry,
                $childId,
                $this->compose($childTransform, $transform),
                $depth + 1,
                $records,
                $count,
            );
        }
    }

    /**
     * Stream a single object's `<mesh>`, transform every vertex into world space
     * once, then pack each triangle (with a computed per-face normal) as a
     * binary STL record. Only this one part's vertex table lives in memory.
     */
    private function emitMesh(string $entry, string $objectId, array $transform, string &$records, int &$count): void
    {
        $reader = $this->openPart($entry);

        try {
            if (! $this->seekObject($reader, $objectId)) {
                return;
            }

            // World-space vertices, indexed as the triangles reference them.
            /** @var list<array{0: float, 1: float, 2: float}> $verts */
            $verts = [];
            $startDepth = $reader->depth;

            $advance = true;
            while (true) {
                if ($advance && ! $reader->read()) {
                    break;
                }
                $advance = true;

                if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $startDepth) {
                    break; // </object>
                }
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }

                if ($reader->localName === 'vertex') {
                    $verts[] = $this->apply(
                        $transform,
                        (float) $reader->getAttribute('x'),
                        (float) $reader->getAttribute('y'),
                        (float) $reader->getAttribute('z'),
                    );

                    continue;
                }

                if ($reader->localName === 'triangle') {
                    $a = $verts[(int) $reader->getAttribute('v1')] ?? null;
                    $b = $verts[(int) $reader->getAttribute('v2')] ?? null;
                    $c = $verts[(int) $reader->getAttribute('v3')] ?? null;
                    if ($a === null || $b === null || $c === null) {
                        continue; // triangle references a vertex we never saw
                    }

                    if (++$count > self::MAX_TRIANGLES) {
                        throw new ThreeMfConversionException(
                            'Model exceeds the '.self::MAX_TRIANGLES.'-triangle cap; refusing to convert.'
                        );
                    }

                    $records .= $this->packTriangle($a, $b, $c);
                }
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * Advance the reader to the `<object>` element with the given id. Returns
     * false if the part contains no such object (a dangling reference).
     */
    private function seekObject(XMLReader $reader, string $objectId): bool
    {
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT
                && $reader->localName === 'object'
                && $reader->getAttribute('id') === $objectId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build (once, cached) the cheap object graph for a model part: every
     * object's id mapped to whether it is a mesh and its component references.
     * Mesh subtrees are skipped here - we only need topology, geometry is
     * streamed later in {@see emitMesh()}.
     *
     * @return array<string, array{mesh: bool, components: list<array{0: ?string, 1: string, 2: list<float>}>}>
     */
    private function graph(string $entry): array
    {
        if (isset($this->graphs[$entry])) {
            return $this->graphs[$entry];
        }

        $reader = $this->openPart($entry);
        $objects = [];

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'object') {
                    continue;
                }
                $id = $reader->getAttribute('id');
                if ($id === null) {
                    continue;
                }
                $objects[$id] = $this->parseObjectNode($reader);
            }
        } finally {
            $reader->close();
        }

        return $this->graphs[$entry] = $objects;
    }

    /**
     * Parse the topology of one `<object>` the reader is positioned on, without
     * reading its mesh geometry. Leaves the reader on the object's `</object>`.
     *
     * @return array{mesh: bool, components: list<array{0: ?string, 1: string, 2: list<float>}>}
     */
    private function parseObjectNode(XMLReader $reader): array
    {
        $object = ['mesh' => false, 'components' => []];
        if ($reader->isEmptyElement) {
            return $object;
        }

        $startDepth = $reader->depth;
        $advance = true;

        while (true) {
            if ($advance && ! $reader->read()) {
                break;
            }
            $advance = true;

            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $startDepth) {
                break; // </object>
            }
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }

            if ($reader->localName === 'mesh') {
                $object['mesh'] = true;
                // An object is a mesh XOR components - skip the (potentially huge)
                // mesh subtree entirely instead of streaming every vertex here.
                $reader->next();
                $advance = false; // next() already positioned us; don't read() past it

                continue;
            }

            if ($reader->localName === 'component') {
                $childId = $reader->getAttribute('objectid');
                if ($childId === null) {
                    continue;
                }
                $object['components'][] = [
                    $reader->getAttributeNs('path', self::PRODUCTION_NS),
                    $childId,
                    $this->parseTransform($reader->getAttribute('transform')),
                ];
            }
        }

        return $object;
    }

    /**
     * Extract a model part from the zip to a temp file (streamed, so a 100 MB
     * part never fully buffers in a PHP string) and return its path. Cached so
     * repeated resolutions of the same part reuse one file.
     */
    private function openPart(string $entry): XMLReader
    {
        if (! isset($this->partFiles[$entry])) {
            $in = $this->zip->getStream($entry);
            if ($in === false) {
                throw new ThreeMfConversionException("Model part '{$entry}' is missing from the .3mf archive.");
            }
            $tmp = tempnam(sys_get_temp_dir(), 'm3d');
            if ($tmp === false) {
                @fclose($in);
                throw new ThreeMfConversionException('Could not allocate a temp file for a model part.');
            }
            $out = fopen($tmp, 'wb');
            if ($out === false) {
                @fclose($in);
                @unlink($tmp);
                throw new ThreeMfConversionException('Could not open a temp file for a model part.');
            }
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
            $this->partFiles[$entry] = $tmp;
        }

        $reader = new XMLReader;
        if (! $reader->open($this->partFiles[$entry])) {
            throw new ThreeMfConversionException("Could not open model part '{$entry}' for parsing.");
        }

        return $reader;
    }

    /**
     * Parse a 3MF `transform` attribute (12 space-separated floats) into our
     * flat [a b c d e f g h i j k l] layout. A missing/short transform is the
     * identity - the plate origin, no rotation or scale.
     *
     * @return list<float>
     */
    private function parseTransform(?string $raw): array
    {
        $identity = [1.0, 0.0, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0];
        if ($raw === null || trim($raw) === '') {
            return $identity;
        }

        $parts = preg_split('/\s+/', trim($raw)) ?: [];
        if (count($parts) < 12) {
            return $identity;
        }

        $m = [];
        for ($i = 0; $i < 12; $i++) {
            $m[] = (float) $parts[$i];
        }

        return $m;
    }

    /**
     * Apply a 3MF transform to a point. The 12 numbers are a 4x3 matrix in 3MF
     * column order - rows (a,b,c)(d,e,f)(g,h,i) are the rotation/scale and
     * (j,k,l) the translation - so (verified against the sample files):
     *   x' = a·x + d·y + g·z + j
     *   y' = b·x + e·y + h·z + k
     *   z' = c·x + f·y + i·z + l
     *
     * @param  list<float>  $m
     * @return array{0: float, 1: float, 2: float}
     */
    private function apply(array $m, float $x, float $y, float $z): array
    {
        return [
            $m[0] * $x + $m[3] * $y + $m[6] * $z + $m[9],
            $m[1] * $x + $m[4] * $y + $m[7] * $z + $m[10],
            $m[2] * $x + $m[5] * $y + $m[8] * $z + $m[11],
        ];
    }

    /**
     * Compose two 3MF transforms so applying the result equals applying $child
     * then $parent (child space -> parent space -> ... -> world). This is what
     * lets a build item's plate placement stack on top of each nested
     * component's local placement.
     *
     * @param  list<float>  $child
     * @param  list<float>  $parent
     * @return list<float>
     */
    private function compose(array $child, array $parent): array
    {
        [$c0, $c1, $c2, $c3, $c4, $c5, $c6, $c7, $c8, $c9, $c10, $c11] = $child;
        [$p0, $p1, $p2, $p3, $p4, $p5, $p6, $p7, $p8, $p9, $p10, $p11] = $parent;

        return [
            $p0 * $c0 + $p3 * $c1 + $p6 * $c2,
            $p1 * $c0 + $p4 * $c1 + $p7 * $c2,
            $p2 * $c0 + $p5 * $c1 + $p8 * $c2,
            $p0 * $c3 + $p3 * $c4 + $p6 * $c5,
            $p1 * $c3 + $p4 * $c4 + $p7 * $c5,
            $p2 * $c3 + $p5 * $c4 + $p8 * $c5,
            $p0 * $c6 + $p3 * $c7 + $p6 * $c8,
            $p1 * $c6 + $p4 * $c7 + $p7 * $c8,
            $p2 * $c6 + $p5 * $c7 + $p8 * $c8,
            $p0 * $c9 + $p3 * $c10 + $p6 * $c11 + $p9,
            $p1 * $c9 + $p4 * $c10 + $p7 * $c11 + $p10,
            $p2 * $c9 + $p5 * $c10 + $p8 * $c11 + $p11,
        ];
    }

    /**
     * Pack one world-space triangle as a 50-byte binary STL record: a computed
     * per-face normal, the three vertices (all little-endian 32-bit floats via
     * `g`), and a zero attribute-byte-count - the exact record layout
     * {@see StlMerger} reads and writes.
     *
     * @param  array{0: float, 1: float, 2: float}  $a
     * @param  array{0: float, 1: float, 2: float}  $b
     * @param  array{0: float, 1: float, 2: float}  $c
     */
    private function packTriangle(array $a, array $b, array $c): string
    {
        // Normal = normalize((b-a) x (c-a)). Degenerate faces get a zero normal,
        // which slicers happily recompute (as StlMerger does for ASCII input).
        $ux = $b[0] - $a[0];
        $uy = $b[1] - $a[1];
        $uz = $b[2] - $a[2];
        $vx = $c[0] - $a[0];
        $vy = $c[1] - $a[1];
        $vz = $c[2] - $a[2];

        $nx = $uy * $vz - $uz * $vy;
        $ny = $uz * $vx - $ux * $vz;
        $nz = $ux * $vy - $uy * $vx;

        $len = sqrt($nx * $nx + $ny * $ny + $nz * $nz);
        if ($len > 0.0) {
            $nx /= $len;
            $ny /= $len;
            $nz /= $len;
        } else {
            $nx = $ny = $nz = 0.0;
        }

        return pack('g3', $nx, $ny, $nz)
            .pack('g3', $a[0], $a[1], $a[2])
            .pack('g3', $b[0], $b[1], $b[2])
            .pack('g3', $c[0], $c[1], $c[2])
            ."\0\0";
    }
}
