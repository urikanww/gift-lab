<?php

declare(strict_types=1);

namespace App\Services\Model3d;

use App\Enums\License;
use App\Enums\Model3dSource;
use App\Services\Model3d\Contracts\Model3dApiClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Live Thingiverse client (public API, free — spec 6.5). Only handles the
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

        $response = Http::withToken($token)->acceptJson()->get("{$base}/things/{$sourceId}");

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();

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
        );
    }

    /**
     * Map a Thingiverse licence label onto our License enum value string.
     */
    private function mapLicense(string $label): string
    {
        $l = Str::lower($label);

        return match (true) {
            str_contains($l, 'public domain') => License::Cc0->value,
            str_contains($l, 'non-commercial') => License::Blocked->value, // NC is not commercial-OK
            str_contains($l, 'attribution') => License::CcBy->value,
            default => License::Blocked->value,
        };
    }
}
