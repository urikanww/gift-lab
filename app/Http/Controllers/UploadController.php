<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ArtworkUploadRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

/**
 * Stores designer artwork on the configured filesystem disk (local in dev,
 * S3/DO Spaces in prod) and returns a stable ref + URL. Kept small and stateless
 * so it works for anonymous designer sessions.
 */
class UploadController extends Controller
{
    public function artwork(ArtworkUploadRequest $request): JsonResponse
    {
        $path = $request->file('artwork')->store('artwork');

        return response()->json([
            'ref' => $path,
            'url' => Storage::url($path),
        ], 201);
    }
}
