<?php

declare(strict_types=1);

use App\Enums\Model3dSource;
use App\Models\Product;
use App\Services\Model3d\Model3dData;
use App\Services\Model3d\StubModel3dApiClient;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    seedPricing();
    config()->set('services.thingiverse.token', 'test-token');
    config()->set('services.thingiverse.base_url', 'https://api.thingiverse.com');
    config()->set('services.cults3d.username', 'test-user');
    config()->set('services.cults3d.token', 'test-token');
    config()->set('services.cults3d.base_url', 'https://cults3d.com/graphql');
});

it('ingests from the Thingiverse popular feed with no search query', function (): void {
    Http::fake([
        'api.thingiverse.com/popular*' => Http::response(['hits' => [['id' => 501]]], 200),
    ]);

    app(StubModel3dApiClient::class)->with(new Model3dData(
        source: Model3dSource::Thingiverse,
        sourceId: '501',
        name: 'Popular phone stand',
        license: 'CC0',
        creatorCredit: null,
        fileRef: 'models/stand.stl',
        filamentMaterial: 'PLA',
        filamentColor: 'Black',
        estGrams: 20.0,
    ));

    $this->artisan('catalogue:pull-3d', ['--browse' => 'popular', '--source' => 'thingiverse'])
        ->assertSuccessful();

    $requests = Http::recorded(fn ($request) => str_contains($request->url(), '/popular'));
    expect($requests)->not->toBeEmpty();

    $product = Product::query()->where('name', 'Popular phone stand')->first();
    expect($product)->not->toBeNull();
});

it('paginates the popular feed until --count commercial-OK items are ingested', function (): void {
    Http::fake([
        'api.thingiverse.com/popular*' => function ($request) {
            $page = (int) ($request->data()['page'] ?? 1);
            // Page 1 returns one hit; page 2 returns another. count=2 means
            // both pages should be consulted, and no third page requested.
            $idsByPage = [
                1 => [['id' => 601]],
                2 => [['id' => 602]],
                3 => [['id' => 603]],
            ];

            return Http::response(['hits' => $idsByPage[$page] ?? []], 200);
        },
    ]);

    $stub = app(StubModel3dApiClient::class);
    foreach ([601, 602, 603] as $id) {
        $stub->with(new Model3dData(
            source: Model3dSource::Thingiverse,
            sourceId: (string) $id,
            name: "Model {$id}",
            license: 'CC0',
            creatorCredit: null,
            fileRef: "models/{$id}.stl",
            filamentMaterial: 'PLA',
            filamentColor: 'Black',
            estGrams: 15.0,
        ));
    }

    $this->artisan('catalogue:pull-3d', ['--browse' => 'popular', '--source' => 'thingiverse', '--count' => 2])
        ->assertSuccessful();

    expect(Product::query()->where('name', 'Model 601')->exists())->toBeTrue()
        ->and(Product::query()->where('name', 'Model 602')->exists())->toBeTrue()
        ->and(Product::query()->where('name', 'Model 603')->exists())->toBeFalse();

    // Third page must never have been requested once --count was satisfied.
    $page3Requests = Http::recorded(fn ($request) => str_contains($request->url(), '/popular')
        && ($request->data()['page'] ?? null) === 3);
    expect($page3Requests)->toBeEmpty();
});

it('still applies the licence gate in browse mode', function (): void {
    Http::fake([
        'api.thingiverse.com/popular*' => Http::response(['hits' => [['id' => 701]]], 200),
    ]);

    app(StubModel3dApiClient::class)->with(new Model3dData(
        source: Model3dSource::Thingiverse,
        sourceId: '701',
        name: 'NC licensed gadget',
        license: 'BLOCKED',
        creatorCredit: null,
        fileRef: 'models/gadget.stl',
        filamentMaterial: 'PLA',
        filamentColor: 'Black',
        estGrams: 15.0,
    ));

    $this->artisan('catalogue:pull-3d', ['--browse' => 'popular', '--source' => 'thingiverse'])
        ->assertSuccessful();

    expect(Product::query()->where('name', 'NC licensed gadget')->exists())->toBeFalse();
});

it('ingests from the Cults3D browse feed with no search query', function (): void {
    Http::fake([
        'cults3d.com/graphql' => Http::response([
            'data' => ['creationsBatch' => [['slug' => 'popular-vase']]],
        ], 200),
    ]);

    app(StubModel3dApiClient::class)->with(new Model3dData(
        source: Model3dSource::Cults3d,
        sourceId: 'popular-vase',
        name: 'Popular vase',
        license: 'CC0',
        creatorCredit: null,
        fileRef: 'models/vase.stl',
        filamentMaterial: 'PLA',
        filamentColor: 'White',
        estGrams: 40.0,
    ));

    $this->artisan('catalogue:pull-3d', ['--browse' => 'popular', '--source' => 'cults3d'])
        ->assertSuccessful();

    $product = Product::query()->where('name', 'Popular vase')->first();
    expect($product)->not->toBeNull();
});

it('errors when neither a query nor --browse is given', function (): void {
    $this->artisan('catalogue:pull-3d', [])
        ->assertFailed();

    expect(Product::query()->count())->toBe(0);
});

it('errors on an unsupported --browse value', function (): void {
    $this->artisan('catalogue:pull-3d', ['--browse' => 'trending'])
        ->assertFailed();
});

it('discover-3d default sweep uses browse mode and respects the configured cap', function (): void {
    Http::fake([
        'api.thingiverse.com/popular*' => Http::response(['hits' => [['id' => 801]]], 200),
        'cults3d.com/graphql' => Http::response([
            'data' => ['creationsBatch' => [['slug' => 'browsed-item']]],
        ], 200),
    ]);

    app(StubModel3dApiClient::class)
        ->with(new Model3dData(
            source: Model3dSource::Thingiverse,
            sourceId: '801',
            name: 'Browsed phone stand',
            license: 'CC0',
            creatorCredit: null,
            fileRef: 'models/stand.stl',
            filamentMaterial: 'PLA',
            filamentColor: 'Black',
            estGrams: 20.0,
        ))
        ->with(new Model3dData(
            source: Model3dSource::Cults3d,
            sourceId: 'browsed-item',
            name: 'Browsed vase',
            license: 'CC0',
            creatorCredit: null,
            fileRef: 'models/vase.stl',
            filamentMaterial: 'PLA',
            filamentColor: 'White',
            estGrams: 40.0,
        ));

    $this->artisan('catalogue:discover-3d')->assertSuccessful();

    expect(Product::query()->where('name', 'Browsed phone stand')->exists())->toBeTrue()
        ->and(Product::query()->where('name', 'Browsed vase')->exists())->toBeTrue();

    // Browse mode never runs a per-keyword search.
    $searchRequests = Http::recorded(fn ($request) => str_contains($request->url(), '/search/'));
    expect($searchRequests)->toBeEmpty();
});
