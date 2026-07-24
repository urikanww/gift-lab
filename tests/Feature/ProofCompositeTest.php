<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Proof;
use App\Models\Quote;
use App\Services\ProofCompositeService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * The proof email embeds ONE flat image. A proof issued from the buyer's
 * designer artwork is a transparent design-only PNG, so it must be flattened
 * onto the product photo first - otherwise the buyer sees their logo floating
 * on white. Uploaded/finished proof files pass through untouched (null here;
 * the caller falls back to the raw artwork URL).
 */
beforeEach(function (): void {
    config(['filesystems.artwork_disk' => 'artwork_test']);
    Storage::fake('artwork_test');
    // The fake local disk cannot presign; route composites through a stub so
    // the S3/Spaces path is exercised end to end.
    Storage::disk('artwork_test')->buildTemporaryUrlsUsing(
        fn (string $path): string => 'https://bucket.test/'.$path,
    );
});

/** Tiny solid PNG. */
function pngBytes(int $w, int $h, array $rgb, bool $transparentBg = false): string
{
    $img = imagecreatetruecolor($w, $h);
    if ($transparentBg) {
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        // Opaque centre square (the "design").
        imagefilledrectangle(
            $img,
            (int) ($w * 0.25), (int) ($h * 0.25),
            (int) ($w * 0.75), (int) ($h * 0.75),
            imagecolorallocatealpha($img, $rgb[0], $rgb[1], $rgb[2], 0),
        );
    } else {
        imagefill($img, 0, 0, imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]));
    }
    ob_start();
    imagepng($img);
    imagedestroy($img);

    return (string) ob_get_clean();
}

function seedDesignerProof(): Proof
{
    $company = Company::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $company->id, 'state' => 'PROOFING']);
    $product = Product::factory()->create(['image_url' => 'https://img.test/product.png']);
    LineItem::factory()->create([
        'quote_id' => $quote->id,
        'product_id' => $product->id,
        'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/design.png'],
    ]);

    return Proof::factory()->create([
        'quote_id' => $quote->id,
        'artwork_version_ref' => 'artwork/design.png',
    ]);
}

it('flattens a designer proof onto its product photo and signs the composite', function (): void {
    // Transparent design PNG on the artwork disk; red product photo over HTTP.
    Storage::disk('artwork_test')->put('artwork/design.png', pngBytes(100, 100, [0, 0, 255], true));
    Http::fake(['img.test/*' => Http::response(pngBytes(200, 100, [255, 0, 0]))]);

    $proof = seedDesignerProof();
    $url = app(ProofCompositeService::class)->signedCompositeUrl($proof, now()->addDay());

    expect($url)->toStartWith('https://bucket.test/artwork/composites/');

    // The stored composite is a square canvas with the product photo underneath
    // (corner pixel red, not white/transparent) and the design on top (centre
    // pixel blue).
    $files = Storage::disk('artwork_test')->files('artwork/composites');
    expect($files)->toHaveCount(1);
    $img = imagecreatefromstring((string) Storage::disk('artwork_test')->get($files[0]));
    expect(imagesx($img))->toBe(1008)->and(imagesy($img))->toBe(1008);

    $corner = imagecolorsforindex($img, imagecolorat($img, 5, 5));
    expect($corner['red'])->toBeGreaterThan(200)->and($corner['blue'])->toBeLessThan(60);

    $centre = imagecolorsforindex($img, imagecolorat($img, 504, 504));
    expect($centre['blue'])->toBeGreaterThan(200)->and($centre['red'])->toBeLessThan(60);
});

it('reuses the stored composite instead of re-rendering per email', function (): void {
    Storage::disk('artwork_test')->put('artwork/design.png', pngBytes(100, 100, [0, 0, 255], true));
    Http::fake(['img.test/*' => Http::response(pngBytes(200, 100, [255, 0, 0]))]);

    $proof = seedDesignerProof();
    $service = app(ProofCompositeService::class);
    $service->signedCompositeUrl($proof, now()->addDay());
    $service->signedCompositeUrl($proof, now()->addDay());

    Http::assertSentCount(1);
    expect(Storage::disk('artwork_test')->files('artwork/composites'))->toHaveCount(1);
});

it('serves the composite through ProofResource artwork_url for designer proofs', function (): void {
    Storage::disk('artwork_test')->put('artwork/design.png', pngBytes(100, 100, [0, 0, 255], true));
    Http::fake(['img.test/*' => Http::response(pngBytes(200, 100, [255, 0, 0]))]);

    $proof = seedDesignerProof();
    $data = \App\Http\Resources\ProofResource::make($proof)->resolve();

    // "View artwork" (and the version thumbnails) must show the flattened
    // design-on-product image, not the transparent design-only PNG.
    expect($data['artwork_url'])->toStartWith('https://bucket.test/artwork/composites/');
});

it('returns null for an uploaded proof that matches no line design', function (): void {
    $company = Company::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $company->id, 'state' => 'PROOFING']);
    $proof = Proof::factory()->create([
        'quote_id' => $quote->id,
        'artwork_version_ref' => 'proofs/upload.pdf',
    ]);

    expect(app(ProofCompositeService::class)->signedCompositeUrl($proof, now()->addDay()))->toBeNull();
});

it('returns null for a buyer_uploaded reference line even when refs match', function (): void {
    $company = Company::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $company->id, 'state' => 'PROOFING']);
    $product = Product::factory()->create(['image_url' => 'https://img.test/product.png']);
    LineItem::factory()->create([
        'quote_id' => $quote->id,
        'product_id' => $product->id,
        'customization' => ['mode' => 'buyer_uploaded', 'artwork_ref' => 'artwork/ref-photo.png'],
    ]);
    $proof = Proof::factory()->create([
        'quote_id' => $quote->id,
        'artwork_version_ref' => 'artwork/ref-photo.png',
    ]);

    expect(app(ProofCompositeService::class)->signedCompositeUrl($proof, now()->addDay()))->toBeNull();
});
