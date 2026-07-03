<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ArtworkUploadRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

/**
 * Stores anonymous designer artwork on the dedicated PRIVATE artwork disk
 * (config('filesystems.artwork_disk'): local in dev, private DO Spaces in prod)
 * and returns a stable ref plus a short-lived SIGNED preview URL. Kept small and
 * stateless so it works for account-free designer sessions.
 *
 * The upload surface is public (spec 6.1), so it must never write to the public
 * bucket: files land private and are only ever exposed through
 * Storage::temporaryUrl, so a leaked key alone grants no lasting access. The
 * frontend persists only 'ref' (the storage key) as the line's artwork_ref;
 * 'url' is a disposable preview link. See config/filesystems.php
 * ('spaces_private').
 */
class UploadController extends Controller
{
    /**
     * How long a returned preview URL stays valid. Short by design — it is only
     * needed to render the just-uploaded thumbnail in the designer.
     */
    private const PREVIEW_URL_TTL_MINUTES = 30;

    public function artwork(ArtworkUploadRequest $request): JsonResponse
    {
        $diskName = (string) config('filesystems.artwork_disk');
        $disk = Storage::disk($diskName);

        // Explicit 'private' visibility: even if the disk's default ever flips
        // to public, an anon upload never becomes world-readable.
        $path = $request->file('artwork')->store('artwork', [
            'disk' => $diskName,
            'visibility' => 'private',
        ]);

        return response()->json([
            'ref' => $path,
            'url' => $disk->temporaryUrl($path, now()->addMinutes(self::PREVIEW_URL_TTL_MINUTES)),
        ], 201);
    }
}
