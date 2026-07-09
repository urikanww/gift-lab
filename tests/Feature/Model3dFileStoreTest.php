<?php

declare(strict_types=1);

use App\Enums\Model3dSource;
use App\Models\Product;
use App\Services\Model3d\Model3dData;
use App\Services\Model3d\Model3dFileStore;
use App\Services\Model3d\StubModel3dApiClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function binaryStlWith(array $triangles): string
{
    $body = '';
    foreach ($triangles as $tri) {
        $body .= pack('g3', 0, 0, 0);
        foreach ($tri as $v) {
            $body .= pack('g3', $v[0], $v[1], $v[2]);
        }
        $body .= "\0\0";
    }

    return str_repeat("\0", 80).pack('V', count($triangles)).$body;
}

function triangleCountOf(string $stl): int
{
    return (int) unpack('V', substr($stl, 80, 4))[1];
}

function makeData(array $downloadFiles): Model3dData
{
    return new Model3dData(
        source: Model3dSource::Thingiverse,
        sourceId: '999',
        name: 'Multi Part',
        license: 'CC_BY',
        creatorCredit: 'Maker',
        fileRef: 'https://www.thingiverse.com/thing:999',
        filamentMaterial: 'PLA',
        filamentColor: 'Black',
        estGrams: 50.0,
        downloadFiles: $downloadFiles,
    );
}

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('services.thingiverse.token', 'test-token');
});

it('merges multi-part STL downloads into one stored file', function (): void {
    $head = binaryStlWith([[[0, 0, 0], [1, 0, 0], [0, 1, 0]]]);
    $body = binaryStlWith([[[0, 0, 0], [0, 0, 5], [5, 0, 0]]]);
    Http::fake([
        'https://dl/head' => Http::response($head, 200),
        'https://dl/body' => Http::response($body, 200),
    ]);

    $path = (new Model3dFileStore)->ensure(makeData([
        ['url' => 'https://dl/head', 'name' => 'groot_head.stl'],
        ['url' => 'https://dl/body', 'name' => 'groot_body.stl'],
    ]));

    expect($path)->not->toBeNull();
    Storage::disk('local')->assertExists($path);
    expect(str_ends_with($path, '.stl'))->toBeTrue();
    expect(triangleCountOf(Storage::disk('local')->get($path)))->toBe(2);
});

it('stores a single STL file unchanged (byte-identical, no merge)', function (): void {
    $one = binaryStlWith([[[0, 0, 0], [1, 0, 0], [0, 1, 0]]]);
    Http::fake(['https://dl/one' => Http::response($one, 200)]);

    $path = (new Model3dFileStore)->ensure(makeData([
        ['url' => 'https://dl/one', 'name' => 'part.stl'],
    ]));

    expect($path)->not->toBeNull();
    expect(Storage::disk('local')->get($path))->toBe($one);
});

it('extracts STL members from a downloaded zip and merges them', function (): void {
    $a = binaryStlWith([[[0, 0, 0], [1, 0, 0], [0, 1, 0]]]);
    $b = binaryStlWith([[[0, 0, 0], [0, 0, 2], [2, 0, 0]]]);
    $zipPath = tempnam(sys_get_temp_dir(), 'z').'.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('parts/a.stl', $a);
    $zip->addFromString('parts/b.stl', $b);
    $zip->addFromString('readme.txt', 'ignore me');
    $zip->close();
    $zipBytes = file_get_contents($zipPath);
    @unlink($zipPath);

    Http::fake(['https://dl/bundle' => Http::response($zipBytes, 200)]);

    $path = (new Model3dFileStore)->ensure(makeData([
        ['url' => 'https://dl/bundle', 'name' => 'bundle.zip'],
    ]));

    expect($path)->not->toBeNull();
    expect(str_ends_with($path, '.stl'))->toBeTrue();
    expect(triangleCountOf(Storage::disk('local')->get($path)))->toBe(2);
});

it('returns null when no printable file is available', function (): void {
    expect((new Model3dFileStore)->ensure(makeData([])))->toBeNull();
});

it('re-downloads and re-merges an already-stored file only when forced', function (): void {
    // Pre-seed a stale single-part file - the artifact of the head-only bug.
    $head = binaryStlWith([[[0, 0, 0], [1, 0, 0], [0, 1, 0]]]);
    Storage::disk('local')->put('models3d/thingiverse-999.stl', $head);

    $body = binaryStlWith([[[0, 0, 0], [0, 0, 5], [5, 0, 0]]]);
    Http::fake([
        'https://dl/head' => Http::response($head, 200),
        'https://dl/body' => Http::response($body, 200),
    ]);
    $data = makeData([
        ['url' => 'https://dl/head', 'name' => 'groot_head.stl'],
        ['url' => 'https://dl/body', 'name' => 'groot_body.stl'],
    ]);
    $store = new Model3dFileStore;

    // Default: cache hit, the stale single-part file is left untouched.
    $path = $store->ensure($data);
    expect(triangleCountOf(Storage::disk('local')->get($path)))->toBe(1);

    // Forced: re-fetch every part and re-merge over the stale file.
    $path = $store->ensure($data, force: true);
    expect(triangleCountOf(Storage::disk('local')->get($path)))->toBe(2);
});

it('heals a stale single-part model file end-to-end via resync --force', function (): void {
    seedPricing();
    config()->set('services.thingiverse.base_url', 'https://api.thingiverse.com');

    $head = binaryStlWith([[[0, 0, 0], [1, 0, 0], [0, 1, 0]]]);
    $body = binaryStlWith([[[0, 0, 0], [0, 0, 5], [5, 0, 0]]]);
    Http::fake([
        'api.thingiverse.com/search/*' => Http::response(['hits' => [['id' => 999]]], 200),
        'https://dl/head' => Http::response($head, 200),
        'https://dl/body' => Http::response($body, 200),
    ]);

    app(StubModel3dApiClient::class)->with(new Model3dData(
        source: Model3dSource::Thingiverse,
        sourceId: '999',
        name: 'Multi Part Bot',
        license: 'CC0',
        creatorCredit: null,
        fileRef: 'https://www.thingiverse.com/thing:999', // http ⇒ store downloads, not a local fixture
        filamentMaterial: 'PLA',
        filamentColor: 'Black',
        estGrams: 30.0,
        downloadFiles: [
            ['url' => 'https://dl/head', 'name' => 'bot_head.stl'],
            ['url' => 'https://dl/body', 'name' => 'bot_body.stl'],
        ],
    ));

    // Initial ingest creates the product with the correctly merged file.
    $this->artisan('catalogue:pull-3d', ['query' => 'bot', '--source' => 'thingiverse'])->assertSuccessful();
    $product = Product::query()->where('name', 'Multi Part Bot')->firstOrFail();

    // Simulate a product ingested BEFORE the fix: only the head was stored, and
    // its (too-small) dimensions were derived from that lone part.
    Storage::disk('local')->put($product->model_file_ref, $head);
    $product->update(['dimensions' => ['l' => 1.0, 'w' => 1.0, 'h' => 1.0, 'unit' => 'mm']]);

    // Plain resync leaves the stale file and dimensions (cache hit).
    $this->artisan('catalogue:resync-3d')->assertSuccessful();
    $product->refresh();
    expect(triangleCountOf(Storage::disk('local')->get($product->model_file_ref)))->toBe(1)
        ->and((float) $product->dimensions['l'])->toBe(1.0);

    // Forced resync re-downloads, re-merges AND recomputes the footprint.
    $this->artisan('catalogue:resync-3d', ['--force' => true])->assertSuccessful();
    $product->refresh();
    expect(triangleCountOf(Storage::disk('local')->get($product->model_file_ref)))->toBe(2)
        ->and((float) $product->dimensions['l'])->toBe(5.0); // combined bounding box
});
