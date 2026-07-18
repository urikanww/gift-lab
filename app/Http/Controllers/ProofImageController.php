<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Proof;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sessionless, signature-authenticated proof thumbnail for emails. The signed
 * URL is the auth (email clients can't send cookies), with a long TTL so a
 * later-opened email still renders. Falls back to 404 if the ref isn't a
 * stored raster image; the email template then shows its placeholder tile.
 */
class ProofImageController extends Controller
{
    public function __invoke(Request $request, Proof $proof): StreamedResponse
    {
        $disk = (string) config('filesystems.artwork_disk', 'local');
        $ref = (string) $proof->artwork_version_ref;

        // artwork_version_ref is a free-form staff-supplied string, so guard the
        // prefix and reject path traversal BEFORE the disk read (a `..` would
        // otherwise throw PathTraversalDetected and 500). Real refs always come
        // from UploadController under `artwork/`; anything else 404s and the
        // email template falls back to its placeholder tile.
        abort_if(
            $ref === ''
            || ! str_starts_with($ref, 'artwork/')
            || str_contains($ref, '..')
            || ! Storage::disk($disk)->exists($ref),
            404,
        );

        return Storage::disk($disk)->response($ref, null, [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
