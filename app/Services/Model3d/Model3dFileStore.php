<?php

declare(strict_types=1);

namespace App\Services\Model3d;

use App\Enums\Model3dSource;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

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

    /**
     * Ensure a local copy of the model file exists; returns the storage path
     * (on the `local` disk) or null when no file could be obtained.
     */
    public function ensure(Model3dData $data): ?string
    {
        // Fixture/owned entries may already reference a local (non-http) file.
        if ($data->fileRef !== null && $data->fileRef !== '' && ! str_starts_with($data->fileRef, 'http')) {
            return $data->fileRef;
        }

        if ($data->downloadUrl === null || $data->downloadUrl === '') {
            return null;
        }

        $extension = $this->extensionFor($data->downloadFileName ?? $data->downloadUrl);
        if ($extension === null) {
            return null;
        }

        $path = sprintf('models3d/%s-%s.%s', strtolower($data->source->value), $data->sourceId, $extension);

        if (Storage::disk('local')->exists($path)) {
            return $path;
        }

        try {
            $request = Http::connectTimeout(5)->timeout(60)->retry(2, 500, throw: false);

            // Thingiverse file downloads require the same bearer token as the API.
            if ($data->source === Model3dSource::Thingiverse) {
                $request = $request->withToken((string) config('services.thingiverse.token'));
            }

            $response = $request->get($data->downloadUrl);

            if (! $response->successful() || $response->body() === '') {
                Log::warning('3D model file download failed.', [
                    'source' => $data->source->value,
                    'source_id' => $data->sourceId,
                    'status' => $response->status(),
                ]);

                return null;
            }

            Storage::disk('local')->put($path, $response->body());

            return $path;
        } catch (Throwable $e) {
            Log::warning('3D model file download failed (transport error).', [
                'source' => $data->source->value,
                'source_id' => $data->sourceId,
                'error' => $e->getMessage(),
            ]);

            return null;
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

        return in_array($extension, self::ALLOWED_EXTENSIONS, true) ? $extension : null;
    }
}
