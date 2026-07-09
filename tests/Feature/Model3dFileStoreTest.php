<?php

declare(strict_types=1);

use App\Enums\Model3dSource;
use App\Models\PricingConfig;
use App\Models\Product;
use App\Services\Model3d\Model3dCatalogueService;
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

// A lone small part (1 triangle) - the "head only" artifact.
function smallPart(): string
{
    return binaryStlWith([[[0, 0, 0], [1, 0, 0], [0, 1, 1]]]);
}

// The richest / most-complete file (3 triangles, larger footprint).
function bigPart(): string
{
    return binaryStlWith([
        [[0, 0, 0], [8, 0, 0], [0, 3, 0]],
        [[0, 0, 0], [0, 3, 3], [8, 0, 0]],
        [[8, 0, 0], [8, 3, 3], [0, 3, 3]],
    ]);
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

it('stores the richest STL when a model ships several files (never merges)', function (): void {
    // Small + big must NOT be stacked - the store keeps the richest single file.
    Http::fake([
        'https://dl/small' => Http::response(smallPart(), 200),
        'https://dl/big' => Http::response(bigPart(), 200),
    ]);

    $path = (new Model3dFileStore)->ensure(makeData([
        ['url' => 'https://dl/small', 'name' => 'part_small.stl'],
        ['url' => 'https://dl/big', 'name' => 'part_big.stl'],
    ]));

    expect($path)->not->toBeNull();
    Storage::disk('local')->assertExists($path);
    expect(str_ends_with($path, '.stl'))->toBeTrue();
    expect(Storage::disk('local')->get($path))->toBe(bigPart());
});

it('stores a single STL file unchanged (byte-identical)', function (): void {
    $one = smallPart();
    Http::fake(['https://dl/one' => Http::response($one, 200)]);

    $path = (new Model3dFileStore)->ensure(makeData([
        ['url' => 'https://dl/one', 'name' => 'part.stl'],
    ]));

    expect($path)->not->toBeNull();
    expect(Storage::disk('local')->get($path))->toBe($one);
});

it('stores the richest STL member from a downloaded zip', function (): void {
    $zipPath = tempnam(sys_get_temp_dir(), 'z').'.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('parts/small.stl', smallPart());
    $zip->addFromString('parts/big.stl', bigPart());
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
    expect(Storage::disk('local')->get($path))->toBe(bigPart());
});

it('returns null when no printable file is available', function (): void {
    expect((new Model3dFileStore)->ensure(makeData([])))->toBeNull();
});

it('persists every part of a multi-part model, flagging the richest primary', function (): void {
    Http::fake([
        'https://dl/small' => Http::response(smallPart(), 200),
        'https://dl/big' => Http::response(bigPart(), 200),
    ]);

    $result = (new Model3dFileStore)->ensureAll(makeData([
        ['url' => 'https://dl/small', 'name' => 'groot_head.stl'],
        ['url' => 'https://dl/big', 'name' => 'groot_body.stl'],
    ]));

    // Primary is the richest file on the legacy path (single-mesh callers still work).
    expect($result['primary'])->toBe('models3d/thingiverse-999.stl');
    expect(Storage::disk('local')->get($result['primary']))->toBe(bigPart());

    // Both parts are recorded; the big one is primary and labelled from its filename.
    expect($result['parts'])->toHaveCount(2);
    $primary = collect($result['parts'])->firstWhere('is_primary', true);
    $secondary = collect($result['parts'])->firstWhere('is_primary', false);
    expect($primary['label'])->toBe('groot body');
    expect($primary['triangle_count'])->toBe(3);
    expect($secondary['label'])->toBe('groot head');
    expect($secondary['triangle_count'])->toBe(1);
    // The non-primary part is stored on its own -partN path.
    Storage::disk('local')->assertExists($secondary['file_ref']);
    expect($secondary['file_ref'])->toBe('models3d/thingiverse-999-part1.stl');
});

it('records no separate parts for a single-file model', function (): void {
    Http::fake(['https://dl/one' => Http::response(bigPart(), 200)]);

    $result = (new Model3dFileStore)->ensureAll(makeData([
        ['url' => 'https://dl/one', 'name' => 'solo.stl'],
    ]));

    expect($result['primary'])->not->toBeNull();
    expect($result['parts'])->toBe([]);
});

it('re-downloads and re-stores an already-stored file only when forced', function (): void {
    // Pre-seed a stale lone-part file - the artifact of the head-only bug.
    Storage::disk('local')->put('models3d/thingiverse-999.stl', smallPart());

    Http::fake([
        'https://dl/small' => Http::response(smallPart(), 200),
        'https://dl/big' => Http::response(bigPart(), 200),
    ]);
    $data = makeData([
        ['url' => 'https://dl/small', 'name' => 'part_small.stl'],
        ['url' => 'https://dl/big', 'name' => 'part_big.stl'],
    ]);
    $store = new Model3dFileStore;

    // Default: cache hit, the stale lone-part file is left untouched.
    $path = $store->ensure($data);
    expect(triangleCountOf(Storage::disk('local')->get($path)))->toBe(1);

    // Forced: re-fetch and re-store the richest file over the stale one.
    $path = $store->ensure($data, force: true);
    expect(Storage::disk('local')->get($path))->toBe(bigPart());
});

it('heals a stale lone-part model file end-to-end via resync --force', function (): void {
    seedPricing();
    config()->set('services.thingiverse.base_url', 'https://api.thingiverse.com');

    Http::fake([
        'api.thingiverse.com/search/*' => Http::response(['hits' => [['id' => 999]]], 200),
        'https://dl/small' => Http::response(smallPart(), 200),
        'https://dl/big' => Http::response(bigPart(), 200),
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
            ['url' => 'https://dl/small', 'name' => 'bot_small.stl'],
            ['url' => 'https://dl/big', 'name' => 'bot_big.stl'],
        ],
    ));

    // Initial ingest creates the product, storing the richest file as primary AND
    // persisting every part - so a multi-file source no longer needs staff review
    // (the whole set is captured, not one dropped part).
    $this->artisan('catalogue:pull-3d', ['query' => 'bot', '--source' => 'thingiverse'])->assertSuccessful();
    $product = Product::query()->where('name', 'Multi Part Bot')->firstOrFail();
    expect($product->cannot_publish_reasons ?? [])->not->toContain('multi_file_review');
    expect($product->modelParts()->count())->toBe(2);

    // Simulate a product ingested BEFORE the fix: only the small part was stored,
    // and its (too-small) dimensions were derived from it.
    Storage::disk('local')->put($product->model_file_ref, smallPart());
    $product->update(['dimensions' => ['l' => 1.0, 'w' => 1.0, 'h' => 1.0, 'unit' => 'mm']]);

    // Plain resync leaves the stale file and dimensions (cache hit).
    $this->artisan('catalogue:resync-3d')->assertSuccessful();
    $product->refresh();
    expect(triangleCountOf(Storage::disk('local')->get($product->model_file_ref)))->toBe(1)
        ->and((float) $product->dimensions['l'])->toBe(1.0);

    // Forced resync re-downloads, stores the richest file AND recomputes the footprint.
    $this->artisan('catalogue:resync-3d', ['--force' => true])->assertSuccessful();
    $product->refresh();
    expect(Storage::disk('local')->get($product->model_file_ref))->toBe(bigPart())
        ->and((float) $product->dimensions['l'])->toBe(8.0); // bigPart spans x 0..8
});

// A multi-part product held under the OLD behaviour (multi_file_review, no parts).
function heldMultiPartProduct(): Product
{
    Storage::disk('local')->put('models3d/held-1.stl', bigPart());

    return Product::factory()->create([
        'class' => 'MODEL_3D',
        'license' => 'CC0',
        'creator_credit' => null,
        'model_file_ref' => 'models3d/held-1.stl',
        'estimates_verified' => true,
        'publish_state' => 'READY_TO_APPROVE',
        'cannot_publish_reasons' => ['multi_file_review'],
    ]);
}

it('auto-clears a legacy multi_file_review hold once parts are recorded', function (): void {
    Storage::fake('local');
    $product = heldMultiPartProduct();
    $service = app(Model3dCatalogueService::class);

    // No parts yet: the hold persists on a re-gate.
    $service->regate($product);
    expect($product->fresh()->cannot_publish_reasons)->toContain('multi_file_review');

    // Backfill records the parts, then a re-gate drops the now-moot hold.
    $product->modelParts()->createMany([
        ['label' => 'Body', 'file_ref' => 'models3d/held-1.stl', 'triangle_count' => 3, 'is_primary' => true, 'sort' => 0],
        ['label' => 'Head', 'file_ref' => 'models3d/held-1-part1.stl', 'triangle_count' => 1, 'is_primary' => false, 'sort' => 1],
    ]);
    $service->regate($product->fresh());
    expect($product->fresh()->cannot_publish_reasons ?? [])->not->toContain('multi_file_review');
});

it('auto-publishes a formerly-held multi-part item once parts exist (no manual clear)', function (): void {
    Storage::fake('local');
    PricingConfig::updateOrCreate(
        ['group' => 'catalogue', 'key' => 'auto_publish'],
        ['value' => true, 'label' => 'Auto-publish', 'is_money' => false, 'currency' => 'SGD'],
    );
    $product = heldMultiPartProduct();
    $product->modelParts()->createMany([
        ['label' => 'A', 'file_ref' => 'models3d/held-1.stl', 'triangle_count' => 3, 'is_primary' => true, 'sort' => 0],
        ['label' => 'B', 'file_ref' => 'models3d/held-1-part1.stl', 'triangle_count' => 1, 'is_primary' => false, 'sort' => 1],
    ]);

    app(Model3dCatalogueService::class)->autoPublishIfCleared($product->fresh());

    expect($product->fresh()->publish_state->value)->toBe('PUBLISHED')
        ->and($product->fresh()->cannot_publish_reasons)->toBeNull();
});
