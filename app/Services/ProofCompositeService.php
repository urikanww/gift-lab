<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Proof;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Flattens a designer-artwork proof onto its product photo for contexts that
 * cannot layer images themselves - email above all. The designer exports a
 * TRANSPARENT PNG of only the design layers (the product backdrop is a DOM
 * layer in the designer, never baked in), and the app puts the product photo
 * back underneath at render time (DesignOnProduct). An email client gets one
 * flat <img>, so without this the buyer sees their logo floating on white.
 *
 * Mirrors DesignOnProduct's stacked-layer geometry: square canvas, product
 * photo cover-cropped to fill, design contain-fitted on top.
 *
 * Composites are derived files: stored once per (artwork, product photo) pair
 * on the artwork disk and re-signed per email. Every failure path returns null
 * - the caller falls back to the raw artwork URL, which is exactly the
 * pre-composite behaviour.
 */
class ProofCompositeService
{
    /** Square canvas edge (px). 2x the email's 504px slot for retina. */
    private const CANVAS = 1008;

    /**
     * Signed URL of the proof flattened onto its product photo, or null when
     * this proof has nothing to composite (not a designer artwork, no product
     * photo, disk cannot presign, or the image work failed).
     */
    public function signedCompositeUrl(Proof $proof, \DateTimeInterface $expiry): ?string
    {
        $productImageUrl = $this->matchingProductImage($proof);
        if ($productImageUrl === null) {
            return null;
        }

        $disk = (string) config('filesystems.artwork_disk', 'local');
        $ref = (string) $proof->artwork_version_ref;
        // Keyed on both inputs: a re-captured design or swapped product photo
        // must yield a fresh composite, not a stale cache hit.
        $compositeRef = 'artwork/composites/'.md5($ref.'|'.$productImageUrl).'.png';

        try {
            if (! Storage::disk($disk)->exists($compositeRef)) {
                $png = $this->render($disk, $ref, $productImageUrl);
                if ($png === null) {
                    return null;
                }
                Storage::disk($disk)->put($compositeRef, $png);
            }

            // Presigned bucket URL on S3/Spaces. A local/dev disk cannot presign
            // and throws; the catch falls back to the raw-artwork behaviour.
            return Storage::disk($disk)->temporaryUrl($compositeRef, $expiry);
        } catch (Throwable $e) {
            Log::warning('Proof composite failed; email falls back to raw artwork.', [
                'proof_id' => $proof->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The product photo behind this proof's artwork, when the artwork is a
     * buyer line design. Matched by ref: staff issued the proof straight from
     * the line's designer artwork, so the line names the product to composite
     * onto. An uploaded/finished proof file matches no line and stays as-is.
     */
    private function matchingProductImage(Proof $proof): ?string
    {
        $ref = (string) $proof->artwork_version_ref;
        if ($ref === '') {
            return null;
        }

        foreach ($proof->quote?->lineItems ?? [] as $line) {
            $customization = $line->customization ?? [];
            if (($customization['mode'] ?? null) === 'buyer_uploaded') {
                continue;
            }
            if (($customization['artwork_ref'] ?? null) !== $ref) {
                continue;
            }
            $imageUrl = (string) ($line->product?->image_url ?? '');
            if ($imageUrl !== '') {
                return $imageUrl;
            }
        }

        return null;
    }

    /** Flattened PNG bytes, or null when either image cannot be loaded. */
    private function render(string $disk, string $designRef, string $productImageUrl): ?string
    {
        $designBytes = Storage::disk($disk)->get($designRef);
        $productBytes = $this->fetchProductImage($productImageUrl);
        if ($designBytes === null || $productBytes === null) {
            return null;
        }

        $design = @imagecreatefromstring($designBytes);
        $product = @imagecreatefromstring($productBytes);
        if ($design === false || $product === false) {
            return null;
        }

        $edge = self::CANVAS;
        $canvas = imagecreatetruecolor($edge, $edge);
        // Emails render on white; so does the app's proof slot.
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));

        // Product photo: cover-crop (fill the square, crop the overflow).
        $pw = imagesx($product);
        $ph = imagesy($product);
        $scale = max($edge / $pw, $edge / $ph);
        $srcW = (int) round($edge / $scale);
        $srcH = (int) round($edge / $scale);
        imagecopyresampled(
            $canvas, $product,
            0, 0,
            (int) round(($pw - $srcW) / 2), (int) round(($ph - $srcH) / 2),
            $edge, $edge, $srcW, $srcH,
        );

        // Design: contain-fit centered, alpha-blended over the product.
        $dw = imagesx($design);
        $dh = imagesy($design);
        $scale = min($edge / $dw, $edge / $dh);
        $dstW = (int) round($dw * $scale);
        $dstH = (int) round($dh * $scale);
        imagecopyresampled(
            $canvas, $design,
            (int) round(($edge - $dstW) / 2), (int) round(($edge - $dstH) / 2),
            0, 0,
            $dstW, $dstH, $dw, $dh,
        );

        imagedestroy($design);
        imagedestroy($product);

        ob_start();
        imagepng($canvas);
        $png = ob_get_clean();
        imagedestroy($canvas);

        return $png === false ? null : $png;
    }

    /** Product photo bytes: absolute URLs fetched over HTTP, refs off the disk. */
    private function fetchProductImage(string $imageUrl): ?string
    {
        if (str_starts_with($imageUrl, 'http://') || str_starts_with($imageUrl, 'https://')) {
            $response = Http::timeout(10)->get($imageUrl);

            return $response->successful() ? $response->body() : null;
        }

        $disk = (string) config('filesystems.artwork_disk', 'local');

        return Storage::disk($disk)->get(ltrim($imageUrl, '/'));
    }
}
