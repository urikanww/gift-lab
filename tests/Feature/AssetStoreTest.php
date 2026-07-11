<?php

declare(strict_types=1);

use App\Services\Model3d\AssetStore;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('stores a model file on the configured disk using the flat canonical ref', function (): void {
    Storage::fake('local');
    config()->set('model3d.disk', 'local');

    $ref = app(AssetStore::class)->storeModelFile('makerworld', '3018896', 'STLBYTES', 'stl');

    // CONTRACT: this literal must equal scraper/model-ref.mjs modelRef('3018896',{ext:'stl'})
    // and pass the CSV importer's model_file_ref regex. See scraper/path.test.mjs.
    expect($ref)->toBe('models3d/makerworld-3018896.stl');
    expect($ref)->toMatch('/^models3d\/[\w.\- ]+\.(3mf|stl|obj)$/');
    Storage::disk('local')->assertExists($ref);
    expect(Storage::disk('local')->get($ref))->toBe('STLBYTES');
});

it('routes model files to whatever disk config points at (not a hard-coded one)', function (): void {
    Storage::fake('local');
    Storage::fake('spaces_models');
    config()->set('model3d.disk', 'spaces_models');

    $ref = app(AssetStore::class)->storeModelFile('thingiverse', '42', '3mfbytes', 'gcode.3mf');

    expect($ref)->toBe('models3d/thingiverse-42.gcode.3mf');
    Storage::disk('spaces_models')->assertExists($ref);
    Storage::disk('local')->assertMissing($ref);
});

it('stores a production file on the production disk (flat ref)', function (): void {
    Storage::fake('local');
    Storage::fake('spaces_models');
    config()->set('model3d.production_disk', 'spaces_models');

    $ref = app(AssetStore::class)->storeProductionFile('makerworld', 'ABC', 'floor', '3mf');

    expect($ref)->toBe('models3d/makerworld-abc.3mf');
    Storage::disk('spaces_models')->assertExists($ref);
});

it('the model ref matches what the scraper uploads (cross-language path contract)', function (): void {
    // The scraper uploads to  {DO_STORAGE_FOLDER}/{ref}  and the spaces_models
    // disk is rooted at {DO_STORAGE_FOLDER}, so the ref the backend produces must
    // be byte-identical to the scraper's modelRef(id). Pinned literal on both
    // sides (scraper/path.test.mjs asserts the same string).
    Storage::fake('local');
    config()->set('model3d.disk', 'local');

    $ref = app(AssetStore::class)->storeModelFile('makerworld', '3018896', 'x', '3mf');

    expect($ref)->toBe('models3d/makerworld-3018896.3mf'); // == scraper modelRef('3018896')
});

it('mirrors a thumbnail to the thumbnail disk and returns its url', function (): void {
    Storage::fake('public');
    config()->set('model3d.thumbnail_disk', 'public');
    Http::fake(['cdn.example.com/*' => Http::response('JPEGBYTES', 200)]);

    $url = app(AssetStore::class)->storeThumbnail('makerworld', '77', 'https://cdn.example.com/x.jpg');

    Storage::disk('public')->assertExists('products/makerworld/77.jpg');
    expect($url)->toContain('products/makerworld/77.jpg');
});

it('silent-skips a failed thumbnail download (returns null so the source url survives)', function (): void {
    Storage::fake('public');
    config()->set('model3d.thumbnail_disk', 'public');
    Http::fake(['cdn.example.com/*' => Http::response('', 500)]);

    $url = app(AssetStore::class)->storeThumbnail('makerworld', '77', 'https://cdn.example.com/x.jpg');

    expect($url)->toBeNull();
    Storage::disk('public')->assertMissing('products/makerworld/77.jpg');
});

it('leaves an already self-hosted thumbnail url untouched', function (): void {
    config()->set('model3d.thumbnail_disk', 'public');

    $selfHosted = 'https://sgp1.digitaloceanspaces.com/GIFT_LAB/products/makerworld/9.jpg';
    $url = app(AssetStore::class)->storeThumbnail('makerworld', '9', $selfHosted);

    expect($url)->toBe($selfHosted);
});
