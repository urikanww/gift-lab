<?php

declare(strict_types=1);

namespace App\Services\Model3d;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * One place that saves catalogue assets - thumbnails and model files - used by
 * BOTH the Thingiverse pull and the CSV/MakerWorld path, so every source stores
 * the same way and switching local <-> S3 is a config flip (config/model3d.php).
 *
 * - Thumbnails -> the thumbnail disk (public `s3` in prod), products/{source}/{id}.jpg,
 *   returned as a public URL. Silent-skip on download failure: the caller keeps the
 *   source URL as a best-effort fallback (a dead thumbnail must not fail an ingest).
 * - Model files -> the model disk (private `spaces_models` in prod),
 *   models3d/{source}-{id}.{ext}, returned as a relative ref (same flat shape as
 *   the legacy models3d/... refs the serving endpoints + CSV importer resolve).
 * - Production files -> the production disk (same default), for the H2S print file.
 */
final class AssetStore
{
    /**
     * Mirror a source thumbnail onto our own storage and return its public URL.
     * Returns null when the remote can't be fetched (caller keeps the source URL).
     * Already-self-hosted URLs are returned unchanged (idempotent re-ingest).
     */
    public function storeThumbnail(string $source, string $sourceId, string $remoteUrl): ?string
    {
        if ($remoteUrl === '' || ! str_starts_with($remoteUrl, 'http')) {
            return null;
        }

        // Already on our disk (local storage or our Spaces folder) - nothing to do.
        if (str_contains($remoteUrl, (string) config('app.url')) || str_contains($remoteUrl, '/GIFT_LAB/')) {
            return $remoteUrl;
        }

        $disk = (string) config('model3d.thumbnail_disk', 'public');
        $path = sprintf('products/%s/%s.jpg', $this->slug($source), $this->slug($sourceId));

        if (! Storage::disk($disk)->exists($path)) {
            try {
                $response = Http::connectTimeout(5)->timeout(20)->get($remoteUrl);
                if (! $response->successful() || $response->body() === '') {
                    return null;
                }
                Storage::disk($disk)->put($path, $response->body());
            } catch (Throwable $e) {
                Log::warning('Thumbnail mirror failed.', [
                    'source' => $source,
                    'source_id' => $sourceId,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }
        }

        return Storage::disk($disk)->url($path);
    }

    /**
     * Store the app model file (viewer/dimensions/estimate-slice) on the model
     * disk and return its relative ref.
     */
    public function storeModelFile(string $source, string $sourceId, string $bytes, string $ext): string
    {
        return $this->putModel((string) config('model3d.disk', 'local'), $source, $sourceId, $bytes, $ext);
    }

    /**
     * Store the print-floor production file (e.g. an H2S .gcode.3mf) on the
     * production disk and return its relative ref.
     */
    public function storeProductionFile(string $source, string $sourceId, string $bytes, string $ext): string
    {
        return $this->putModel((string) config('model3d.production_disk', 'local'), $source, $sourceId, $bytes, $ext);
    }

    private function putModel(string $disk, string $source, string $sourceId, string $bytes, string $ext): string
    {
        // Flat models3d/{source}-{id}.{ext} - the SAME convention Model3dFileStore
        // and the scraper use, and the only shape the CSV importer's model_file_ref
        // regex accepts (no sub-slashes). Keep all three in lockstep.
        $path = sprintf('models3d/%s-%s.%s', $this->slug($source), $this->slug($sourceId), ltrim($ext, '.'));
        Storage::disk($disk)->put($path, $bytes);

        return $path;
    }

    /**
     * Filesystem-safe path segment: lowercase, keep word chars / dot / dash,
     * collapse everything else to '-'. Keeps refs predictable across sources.
     */
    private function slug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = (string) preg_replace('/[^a-z0-9._-]+/', '-', $slug);

        return trim($slug, '-') ?: 'item';
    }
}
