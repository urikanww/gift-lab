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
 * Live Cults3D client (GraphQL API, HTTP Basic auth with account nick +
 * API key - spec 6.5). Only handles the CULTS3D source; other sources return
 * null so the composite can fall through. sourceId is the creation slug.
 * The API licence code is mapped to our License enum; the licence gate in
 * Model3dCatalogueService then decides publishability (NC/unknown → blocked).
 */
final class HttpCults3dClient implements Model3dApiClient
{
    public function fetch(Model3dSource $source, string $sourceId): ?Model3dData
    {
        if ($source !== Model3dSource::Cults3d) {
            return null;
        }

        $query = <<<'GQL'
        query ($slug: String!) {
          creation(slug: $slug) {
            slug
            name
            url
            description
            illustrationImageUrl
            license { code name }
            creator { nick }
          }
        }
        GQL;

        try {
            $response = Http::withBasicAuth(
                (string) config('services.cults3d.username'),
                (string) config('services.cults3d.token'),
            )
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(20)
                ->retry(2, 500, throw: false)
                ->post((string) config('services.cults3d.base_url'), [
                    'query' => $query,
                    'variables' => ['slug' => $sourceId],
                ]);
        } catch (Throwable $e) {
            Log::warning('Cults3D fetch failed (transport error).', [
                'source_id' => $sourceId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $creation = $response->json('data.creation');

        if (! $response->successful() || $creation === null) {
            Log::warning('Cults3D fetch returned no creation.', [
                'source_id' => $sourceId,
                'status' => $response->status(),
            ]);

            return null;
        }

        return new Model3dData(
            source: Model3dSource::Cults3d,
            sourceId: $sourceId,
            name: (string) ($creation['name'] ?? "Cults3D {$sourceId}"),
            license: $this->mapLicense((string) ($creation['license']['code'] ?? '')),
            creatorCredit: $creation['creator']['nick'] ?? null,
            fileRef: $creation['url'] ?? null,
            filamentMaterial: 'PLA',
            filamentColor: 'Black',
            estGrams: 50.0,
            imageUrl: $creation['illustrationImageUrl'] ?? null,
            description: isset($creation['description'])
                ? Str::limit(trim(strip_tags((string) $creation['description'])), 500)
                : null,
        );
    }

    /**
     * Map a Cults3D licence code onto our License enum value string.
     * Codes observed on the API: cc0/pd, cc_by, cc_by_sa, cc_by_nc,
     * cc_by_nd, private-use variants. Only CC0 and plain CC-BY are
     * commercial-OK for us; everything else (NC, ND, SA, custom) is blocked.
     */
    private function mapLicense(string $code): string
    {
        $c = Str::lower($code);

        $nc = str_contains($c, 'nc');
        $nd = str_contains($c, 'nd');
        $sa = str_contains($c, 'sa');

        return match (true) {
            $c === 'cc0' || $c === 'pd' || str_contains($c, 'public_domain') => License::Cc0->value,
            // Every CC variant is publish-eligible (operator accepted NC/ND
            // risk). Combos most-specific first.
            str_starts_with($c, 'cc_by') && $nc && $sa => License::CcByNcSa->value,
            str_starts_with($c, 'cc_by') && $nc && $nd => License::CcByNcNd->value,
            str_starts_with($c, 'cc_by') && $nc => License::CcByNc->value,
            str_starts_with($c, 'cc_by') && $nd => License::CcByNd->value,
            str_starts_with($c, 'cc_by') && $sa => License::CcBySa->value,
            $c === 'cc_by' => License::CcBy->value,
            str_contains($c, 'lgpl') => License::Lgpl->value,
            str_contains($c, 'gpl') => License::Gpl->value,
            str_contains($c, 'bsd') => License::Bsd->value,
            str_contains($c, 'apache') => License::Apache->value,
            $c === 'mit' => License::Mit->value,
            // Cults custom / paid / unknown licences grant no resale right.
            default => License::Blocked->value,
        };
    }
}
