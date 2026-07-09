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

    /**
     * Ensure a local copy of the model file exists; returns the storage path
     * (on the `local` disk) or null when no file could be obtained.
     *
     * Multi-part models expose several printable files (and sometimes a `.zip`
     * bundle). All STL parts are merged into a single stored file so nothing is
     * dropped - the fix for the "Baby Groot head only" bug where only the first
     * file was kept. A lone file is stored byte-for-byte unchanged.
     *
     * Pass $force to bypass the cache and re-download/re-merge - the way to heal
     * products ingested before the multi-part fix, whose stored file is the lone
     * part. Their dimensions are NOT recomputed here (they may be staff-set); a
     * separate backfill owns that.
     */
    public function ensure(Model3dData $data, bool $force = false): ?string
    {
        // Fixture/owned entries may already reference a local (non-http) file.
        if ($data->fileRef !== null && $data->fileRef !== '' && ! str_starts_with($data->fileRef, 'http')) {
            return $data->fileRef;
        }

        $files = $this->downloadTargets($data);
        if ($files === []) {
            return null;
        }

        // Cache hit: a previous ingest already stored the file in any supported
        // format. Avoids re-downloading on the daily resync (skipped when forced).
        if (! $force) {
            foreach (self::ALLOWED_EXTENSIONS as $ext) {
                $existing = sprintf('models3d/%s-%s.%s', strtolower($data->source->value), $data->sourceId, $ext);
                if (Storage::disk('local')->exists($existing)) {
                    return $existing;
                }
            }
        }

        // Download every file, expanding any zip bundles into their mesh members.
        $stls = [];
        $others = []; // ext => content (3MF/OBJ cannot be merged; keep the first)
        foreach ($files as $file) {
            $body = $this->download($data->source, (string) $file['url']);
            if ($body === null) {
                continue;
            }
            $ext = $this->extensionFor((string) ($file['name'] ?? $file['url']));
            if ($ext === 'zip' || $this->looksLikeZip($body)) {
                $this->collectFromZip($body, $stls, $others);
            } elseif ($ext === 'stl') {
                $stls[] = $body;
            } elseif ($ext !== null) {
                $others[$ext] ??= $body;
            }
        }

        // Prefer STL. When a model ships several printable files they are a mix
        // of the complete model, individual parts, and alternate print layouts,
        // with no reliable way to tell them apart automatically - so we store the
        // richest single file (most triangles) rather than merge (merging stacks
        // overlapping duplicates). The catalogue service flags multi-file models
        // for staff review. A staff-triggered merge can use StlMerger later.
        if ($stls !== []) {
            return $this->store($data, 'stl', $this->largest($stls));
        }

        // No STL geometry: fall back to a single 3MF/OBJ (unmergeable formats).
        foreach (['3mf', 'obj'] as $ext) {
            if (isset($others[$ext])) {
                return $this->store($data, $ext, $others[$ext]);
            }
        }

        Log::warning('3D model had no usable printable file after download.', [
            'source' => $data->source->value,
            'source_id' => $data->sourceId,
        ]);

        return null;
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

    /**
     * The richest STL (most triangles) - the best-effort "most complete" file
     * when a model ships several.
     *
     * @param  list<string>  $stls
     */
    private function largest(array $stls): string
    {
        $best = $stls[0];
        $bestCount = $this->merger->triangleCount($best);
        foreach (array_slice($stls, 1) as $stl) {
            $count = $this->merger->triangleCount($stl);
            if ($count > $bestCount) {
                $best = $stl;
                $bestCount = $count;
            }
        }

        return $best;
    }

    private function store(Model3dData $data, string $ext, string $content): string
    {
        $path = sprintf('models3d/%s-%s.%s', strtolower($data->source->value), $data->sourceId, $ext);
        Storage::disk('local')->put($path, $content);

        return $path;
    }

    private function looksLikeZip(string $body): bool
    {
        return str_starts_with($body, "PK\x03\x04");
    }

    /**
     * Expand a zip bundle's mesh members into the running STL / other lists.
     *
     * @param  list<string>  $stls
     * @param  array<string, string>  $others
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
                    $stls[] = $member;
                } else {
                    $others[$ext] ??= $member;
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
