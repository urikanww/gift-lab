<?php

declare(strict_types=1);

namespace App\Services\Model3d;

use App\Enums\Model3dSource;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;
use ZipArchive;

/**
 * Downloads and stores the printable model file for an ingested 3D item.
 * The production floor prints from OUR copy, never from a live source link -
 * a source can delete or re-licence a model after a customer has quoted
 * against it (spec 6.6: the Job carries a print-ready file). Files live on
 * the private `local` disk: the CC licence lets us produce prints, not
 * redistribute the file itself.
 */
final class Model3dFileStore
{
    private const ALLOWED_EXTENSIONS = ['stl', '3mf', 'obj'];

    public function __construct(private readonly StlMerger $merger = new StlMerger) {}

    /** The configured model-file disk (local in dev, spaces_models in prod). */
    private function disk(): string
    {
        return (string) config('model3d.disk', 'local');
    }

    /**
     * Ensure a local copy of the model's primary printable file exists; returns
     * its storage path (on the `local` disk) or null when none could be obtained.
     * Thin wrapper over {@see ensureAll()} for callers that only need the primary.
     */
    public function ensure(Model3dData $data, bool $force = false): ?string
    {
        return $this->ensureAll($data, $force)['primary'];
    }

    /**
     * Download and store the model's printable geometry, returning the primary
     * file plus every individual part.
     *
     * Multi-part models expose several printable STL files (and sometimes a
     * `.zip` bundle). We keep the richest single file as the primary (mirrored to
     * products.model_file_ref - the mesh the slicer prints and the PDP previews),
     * AND persist each part separately so nothing is dropped - the fix for the
     * "Baby Groot head only" bug where only one file survived. Parts are stored
     * as models3d/{source}-{id}-partN.stl; the primary reuses the legacy
     * models3d/{source}-{id}.stl path (no duplicate on disk). A single-file model
     * yields an empty parts list - the primary alone is the whole model.
     *
     * Pass $force to bypass the cache and re-download - the way to heal products
     * ingested before multi-part persistence, whose parts were never stored.
     *
     * @return array{primary: ?string, parts: list<array{file_ref: string, label: ?string, triangle_count: int, is_primary: bool, sort: int}>}
     */
    public function ensureAll(Model3dData $data, bool $force = false): array
    {
        // Fixture/owned entries may already reference a local (non-http) file.
        if ($data->fileRef !== null && $data->fileRef !== '' && ! str_starts_with($data->fileRef, 'http')) {
            return ['primary' => $data->fileRef, 'parts' => []];
        }

        $files = $this->downloadTargets($data);
        if ($files === []) {
            return ['primary' => null, 'parts' => []];
        }

        // Cache hit: a previous ingest already stored the primary file. Avoids
        // re-downloading on the daily resync (skipped when forced). Recorded part
        // rows already stand, so we don't re-derive them here.
        if (! $force) {
            foreach (self::ALLOWED_EXTENSIONS as $ext) {
                $existing = sprintf('models3d/%s-%s.%s', strtolower($data->source->value), $data->sourceId, $ext);
                if (Storage::disk($this->disk())->exists($existing)) {
                    return ['primary' => $existing, 'parts' => []];
                }
            }
        }

        // Download every file, expanding any zip bundles into their mesh members.
        // Each entry keeps its source filename so a part can be labelled.
        $stls = [];   // list<array{name: ?string, body: string}>
        $others = []; // ext => array{name: ?string, body: string} (3MF/OBJ: keep first)
        foreach ($files as $file) {
            $body = $this->download($data->source, (string) $file['url']);
            if ($body === null) {
                continue;
            }
            $name = isset($file['name']) ? (string) $file['name'] : null;
            $ext = $this->extensionFor((string) ($file['name'] ?? $file['url']));
            if ($ext === 'zip' || $this->looksLikeZip($body)) {
                $this->collectFromZip($body, $stls, $others);
            } elseif ($ext === 'stl') {
                $stls[] = ['name' => $name, 'body' => $body];
            } elseif ($ext !== null) {
                $others[$ext] ??= ['name' => $name, 'body' => $body];
            }
        }

        if ($stls !== []) {
            return $this->storeStlSet($data, $stls);
        }

        // No STL geometry: fall back to a single 3MF/OBJ (unmergeable formats).
        foreach (['3mf', 'obj'] as $ext) {
            if (isset($others[$ext])) {
                return ['primary' => $this->store($data, $ext, $others[$ext]['body']), 'parts' => []];
            }
        }

        Log::warning('3D model had no usable printable file after download.', [
            'source' => $data->source->value,
            'source_id' => $data->sourceId,
        ]);

        return ['primary' => null, 'parts' => []];
    }

    /**
     * Store the primary STL (richest, on the legacy path) and, when the model
     * ships more than one part, persist each part as -partN.stl and describe it.
     *
     * @param  list<array{name: ?string, body: string}>  $stls
     * @return array{primary: ?string, parts: list<array{file_ref: string, label: ?string, triangle_count: int, is_primary: bool, sort: int}>}
     */
    private function storeStlSet(Model3dData $data, array $stls): array
    {
        // Triangle count per file; the richest is the primary geometry.
        $counts = array_map(fn (array $p): int => $this->merger->triangleCount($p['body']), $stls);
        $primaryIdx = 0;
        foreach ($counts as $i => $c) {
            if ($c > $counts[$primaryIdx]) {
                $primaryIdx = $i;
            }
        }

        // Primary always lives on the legacy models3d/{source}-{id}.stl path so
        // model_file_ref and the existing stream endpoints keep working.
        $primaryPath = $this->store($data, 'stl', $stls[$primaryIdx]['body']);

        // A single-file model is just the primary - no separate part rows.
        if (count($stls) <= 1) {
            return ['primary' => $primaryPath, 'parts' => []];
        }

        $parts = [];
        foreach ($stls as $i => $p) {
            $isPrimary = $i === $primaryIdx;
            $fileRef = $isPrimary ? $primaryPath : $this->storePart($data, $i, $p['body']);
            $parts[] = [
                'file_ref' => $fileRef,
                'label' => $this->labelFrom($p['name'], $i),
                'triangle_count' => $counts[$i],
                'is_primary' => $isPrimary,
                'sort' => $i,
            ];
        }

        return ['primary' => $primaryPath, 'parts' => $parts];
    }

    /**
     * Derive a human part label from the source filename ("Groot_Head.stl" →
     * "Groot Head"); falls back to a 1-based ordinal when unnamed.
     */
    private function labelFrom(?string $name, int $index): ?string
    {
        if ($name === null || trim($name) === '') {
            return 'Part '.($index + 1);
        }
        $base = trim((string) preg_replace('/[_\-]+/', ' ', pathinfo($name, PATHINFO_FILENAME)));

        return $base === '' ? 'Part '.($index + 1) : $base;
    }

    private function storePart(Model3dData $data, int $index, string $content): string
    {
        $path = sprintf(
            'models3d/%s-%s-part%d.stl',
            strtolower($data->source->value),
            $data->sourceId,
            $index + 1,
        );
        Storage::disk($this->disk())->put($path, $content);

        return $path;
    }

    /**
     * The list of files to fetch: the multi-file set when present, else the
     * single legacy download URL.
     *
     * @return list<array{url: string, name: string}>
     */
    private function downloadTargets(Model3dData $data): array
    {
        if ($data->downloadFiles !== []) {
            return $data->downloadFiles;
        }
        if ($data->downloadUrl !== null && $data->downloadUrl !== '') {
            return [['url' => $data->downloadUrl, 'name' => $data->downloadFileName ?? $data->downloadUrl]];
        }

        return [];
    }

    private function download(Model3dSource $source, string $url): ?string
    {
        try {
            $request = Http::connectTimeout(5)->timeout(60)->retry(2, 500, throw: false);

            // Thingiverse file downloads require the same bearer token as the API.
            if ($source === Model3dSource::Thingiverse) {
                $request = $request->withToken((string) config('services.thingiverse.token'));
            }

            $response = $request->get($url);

            if (! $response->successful() || $response->body() === '') {
                Log::warning('3D model file download failed.', [
                    'source' => $source->value,
                    'url' => $url,
                    'status' => $response->status(),
                ]);

                return null;
            }

            return $response->body();
        } catch (Throwable $e) {
            Log::warning('3D model file download failed (transport error).', [
                'source' => $source->value,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function store(Model3dData $data, string $ext, string $content): string
    {
        $path = sprintf('models3d/%s-%s.%s', strtolower($data->source->value), $data->sourceId, $ext);
        Storage::disk($this->disk())->put($path, $content);

        return $path;
    }

    private function looksLikeZip(string $body): bool
    {
        return str_starts_with($body, "PK\x03\x04");
    }

    /**
     * Expand a zip bundle's mesh members into the running STL / other lists,
     * carrying each member's name so a part can be labelled.
     *
     * @param  list<array{name: ?string, body: string}>  $stls
     * @param  array<string, array{name: ?string, body: string}>  $others
     */
    private function collectFromZip(string $zipBytes, array &$stls, array &$others): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'm3d').'.zip';
        file_put_contents($tmp, $zipBytes);
        $zip = new ZipArchive;

        try {
            if ($zip->open($tmp) !== true) {
                return;
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                $ext = $this->extensionFor($name);
                if ($ext === null) {
                    continue;
                }
                $member = $zip->getFromIndex($i);
                if ($member === false || $member === '') {
                    continue;
                }
                if ($ext === 'stl') {
                    $stls[] = ['name' => $name, 'body' => $member];
                } else {
                    $others[$ext] ??= ['name' => $name, 'body' => $member];
                }
            }
            $zip->close();
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Extract a supported model-file extension from a filename or URL
     * (query strings stripped); null when it is not a printable format.
     */
    private function extensionFor(string $nameOrUrl): ?string
    {
        $path = (string) (parse_url($nameOrUrl, PHP_URL_PATH) ?: $nameOrUrl);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        // 'zip' is recognised for classification (it is expanded, never stored).
        return in_array($extension, [...self::ALLOWED_EXTENSIONS, 'zip'], true) ? $extension : null;
    }
}
