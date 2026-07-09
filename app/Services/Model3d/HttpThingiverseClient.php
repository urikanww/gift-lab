<?php

declare(strict_types=1);

namespace App\Services\Model3d;

use App\Enums\License;
use App\Enums\Model3dSource;
use App\Services\Model3d\Contracts\Model3dApiClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Live Thingiverse client (public API, free - spec 6.5). Only handles the
 * THINGIVERSE source; other sources return null so a composite can fall through.
 * The API's licence string is mapped to our License enum; the licence gate in
 * Model3dCatalogueService then decides publishability (NC/unknown → blocked).
 *
 * Filament material/colour/grams are not provided by the API, so sensible
 * defaults are set for staff to adjust in the catalogue gate before publishing.
 */
final class HttpThingiverseClient implements Model3dApiClient
{
    public function fetch(Model3dSource $source, string $sourceId): ?Model3dData
    {
        if ($source !== Model3dSource::Thingiverse) {
            return null;
        }

        $base = (string) config('services.thingiverse.base_url');
        $token = (string) config('services.thingiverse.token');

        try {
            // Explicit connect/request timeouts + bounded retry - Laravel's HTTP
            // client has NO default request timeout, so without these a hung
            // upstream would block the caller (and the daily resync worker)
            // indefinitely. Retry rides out transient blips with backoff.
            $response = Http::withToken($token)
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(15)
                ->retry(2, 500, throw: false)
                ->get("{$base}/things/{$sourceId}");
        } catch (Throwable $e) {
            Log::warning('Thingiverse fetch failed (transport error).', [
                'source_id' => $sourceId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            // Non-2xx (quota, 404, outage): surface a signal instead of a silent
            // null so upstream failures are visible in logs/metrics.
            Log::warning('Thingiverse fetch returned non-success status.', [
                'source_id' => $sourceId,
                'status' => $response->status(),
            ]);

            return null;
        }

        $data = $response->json();
        $files = $this->printableFiles($base, $token, $sourceId);
        $first = $files[0] ?? null;

        return new Model3dData(
            source: Model3dSource::Thingiverse,
            sourceId: $sourceId,
            name: (string) ($data['name'] ?? "Thingiverse #{$sourceId}"),
            license: $this->mapLicense((string) ($data['license'] ?? '')),
            creatorCredit: $data['creator']['name'] ?? null,
            fileRef: $data['public_url'] ?? null,
            filamentMaterial: 'PLA',
            filamentColor: 'Black',
            estGrams: 50.0,
            imageUrl: $data['thumbnail'] ?? $data['default_image']['url'] ?? null,
            description: isset($data['description'])
                ? Str::limit(trim(strip_tags((string) $data['description'])), 500)
                : null,
            // Single fields kept for back-compat; the store prefers downloadFiles.
            downloadUrl: $first['url'] ?? null,
            downloadFileName: $first['name'] ?? null,
            downloadFiles: $files,
        );
    }

    /**
     * Resolve the direct download URL + filename of EVERY printable file
     * (STL/3MF/OBJ or a `.zip` bundle) for the thing, via /things/{id}/files.
     * Empty on any failure - the ingest gate then blocks the item on
     * `missing_model_file` instead of publishing something we cannot produce.
     *
     * @return list<array{url: string, name: string}>
     */
    private function printableFiles(string $base, string $token, string $sourceId): array
    {
        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(15)
                ->retry(2, 500, throw: false)
                ->get("{$base}/things/{$sourceId}/files");
        } catch (Throwable $e) {
            Log::warning('Thingiverse files fetch failed (transport error).', [
                'source_id' => $sourceId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        if (! $response->successful()) {
            Log::warning('Thingiverse files fetch returned non-success status.', [
                'source_id' => $sourceId,
                'status' => $response->status(),
            ]);

            return [];
        }

        $files = collect((array) $response->json());

        // Every printable file (STL/3MF/OBJ) plus any `.zip` bundle - multi-part
        // models split their geometry across several files, and keeping only the
        // first loses parts (the "Baby Groot head only" bug). The store persists
        // every part downstream (largest = primary).
        return $files
            ->filter(fn ($file): bool => is_array($file)
                && $this->isPrintableName((string) ($file['name'] ?? ''))
                && ! empty($file['download_url']))
            ->map(fn ($file): array => [
                'url' => (string) $file['download_url'],
                'name' => (string) $file['name'],
            ])
            ->values()
            ->all();
    }

    private function isPrintableName(string $name): bool
    {
        foreach (['stl', '3mf', 'obj', 'zip'] as $extension) {
            if (str_ends_with(Str::lower($name), ".{$extension}")) {
                return true;
            }
        }

        return false;
    }

    /**
     * Map a Thingiverse licence label onto our License enum value string.
     * Restrictive markers (NC/ND/SA) must be checked before the generic
     * "attribution" match: "Attribution - Share Alike" and "Attribution -
     * No Derivatives" contain "attribution" but are not plain CC-BY - SA
     * imposes share-alike obligations and ND forbids the derivative works
     * our personalisation flow produces.
     */
    private function mapLicense(string $label): string
    {
        $l = Str::lower($label);

        $nc = str_contains($l, 'non-commercial');
        $nd = str_contains($l, 'no derivative');
        $sa = str_contains($l, 'share alike');

        return match (true) {
            str_contains($l, 'public domain') => License::Cc0->value,
            // Every CC variant is publish-eligible (operator accepted NC/ND
            // risk). Match combos most-specific first.
            $nc && $sa => License::CcByNcSa->value,
            $nc && $nd => License::CcByNcNd->value,
            $nc => License::CcByNc->value,
            $nd => License::CcByNd->value,
            $sa => License::CcBySa->value,
            str_contains($l, 'attribution') => License::CcBy->value,
            // Open-source families (Thingiverse: "GNU - GPL", "GNU - LGPL", "BSD").
            // LGPL before GPL - 'lgpl' contains 'gpl'.
            str_contains($l, 'lgpl') => License::Lgpl->value,
            str_contains($l, 'gpl') => License::Gpl->value,
            str_contains($l, 'bsd') => License::Bsd->value,
            str_contains($l, 'apache') => License::Apache->value,
            $l === 'mit' || str_contains($l, 'mit license') => License::Mit->value,
            // No permission granted (All Rights Reserved / Nokia / unknown).
            default => License::Blocked->value,
        };
    }
}
