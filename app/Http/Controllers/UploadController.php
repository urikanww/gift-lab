<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ArtworkUploadRequest;
use App\Http\Requests\ProofUploadRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
     * How long a returned preview URL stays valid. Short by design - it is only
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

    /**
     * Staff proof upload. Stores alongside designer artwork on the same private
     * disk and returns the same {ref, url} shape, so the proof endpoints keep
     * taking an opaque string and nothing downstream needs to know whether that
     * string came from a file picker or was pasted in.
     *
     * Kept under its own key prefix ('proofs/') so the two upload surfaces stay
     * distinguishable in storage: one is public and account-free, the other is
     * staff-only, and a future retention or access rule will almost certainly
     * want to treat them differently.
     */
    public function proof(ProofUploadRequest $request): JsonResponse
    {
        $diskName = (string) config('filesystems.artwork_disk');
        $disk = Storage::disk($diskName);

        $path = $request->file('proof')->store('proofs', [
            'disk' => $diskName,
            'visibility' => 'private',
        ]);

        return response()->json([
            'ref' => $path,
            'url' => $disk->temporaryUrl($path, now()->addMinutes(self::PREVIEW_URL_TTL_MINUTES)),
        ], 201);
    }

    /**
     * Re-issue a short-lived signed preview URL for a previously stored artwork
     * ref, so a buyer can see their saved customization in the cart. Honours the
     * private-disk model: only re-mints a temporary link (never a permanent one),
     * and only for keys inside the artwork namespace.
     */
    public function artworkPreview(Request $request): JsonResponse
    {
        $ref = (string) $request->query('ref', '');
        $disk = Storage::disk((string) config('filesystems.artwork_disk'));

        if ($ref === '' || ! str_starts_with($ref, 'artwork/') || str_contains($ref, '..') || ! $disk->exists($ref)) {
            return response()->json(['message' => 'Artwork not found.'], 404);
        }

        return response()->json([
            'url' => $disk->temporaryUrl($ref, now()->addMinutes(self::PREVIEW_URL_TTL_MINUTES)),
        ]);
    }
}
