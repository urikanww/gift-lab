<?php

declare(strict_types=1);

use App\Enums\Model3dSource;
use App\Enums\PublishState;
use App\Models\Product;
use App\Services\Model3d\Model3dData;
use App\Services\Model3d\StubModel3dApiClient;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    seedPricing();
    config()->set('services.thingiverse.token', 'test-token');
    config()->set('services.thingiverse.base_url', 'https://api.thingiverse.com');
});

/**
 * A published MODEL_3D product whose source now returns null on fetch - stands
 * in for a rate-limited (or genuinely dead) source during a resync.
 */
function publishedProductWithNullSource(): Product
{
    Http::fake([
        'api.thingiverse.com/search/*' => Http::response(['hits' => [['id' => 777]]], 200),
    ]);
    app(StubModel3dApiClient::class)->with(new Model3dData(
        source: Model3dSource::Thingiverse,
        sourceId: '777',
        name: 'Dead Source Widget',
        license: 'CC0',
        creatorCredit: null,
        fileRef: 'models/pikachu.stl', // non-http local fixture ⇒ passes the file gate
        filamentMaterial: 'PLA',
        filamentColor: 'Black',
        estGrams: 30.0,
    ));

    test()->artisan('catalogue:pull-3d', ['query' => 'widget', '--source' => 'thingiverse'])->assertSuccessful();
    $product = Product::query()->where('name', 'Dead Source Widget')->firstOrFail();

    // Publish it, then repoint the model at an unregistered source id so the
    // next resync fetch returns null.
    $product->update(['publish_state' => PublishState::Published]);
    $product->model3d->update(['publish_state' => PublishState::Published, 'source_id' => '888']);

    return $product;
}

it('does NOT unpublish on a null fetch during a forced heal (transient rate-limit)', function (): void {
    $product = publishedProductWithNullSource();

    test()->artisan('catalogue:resync-3d', ['--force' => true])->assertSuccessful();

    $product->refresh();
    expect($product->publish_state)->toBe(PublishState::Published);
});

it('still marks a null-fetch source dead on the normal daily resync', function (): void {
    $product = publishedProductWithNullSource();

    test()->artisan('catalogue:resync-3d')->assertSuccessful();

    $product->refresh();
    expect($product->publish_state)->toBe(PublishState::CannotPublish)
        ->and($product->cannot_publish_reasons)->toContain('source_dead');
});
