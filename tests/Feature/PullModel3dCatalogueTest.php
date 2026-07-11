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
});

it('flags an IP-risk model NON-BLOCKINGLY (tag, not CANNOT_PUBLISH)', function (): void {
    // Policy (catalogue-s3-bambu plan, Phase 4): an IP-flagged but otherwise
    // valid item is surfaced with a non-blocking ip_flagged tag + held for human
    // approval - it is NOT forced to CANNOT_PUBLISH. A CC0 model with a local
    // file therefore reaches READY_TO_APPROVE while carrying the flag.
    Http::fake([
        'api.thingiverse.com/search/*' => Http::response(['hits' => [['id' => 987]]], 200),
    ]);

    app(StubModel3dApiClient::class)->with(new Model3dData(
        source: Model3dSource::Thingiverse,
        sourceId: '987',
        name: 'Pikachu phone stand',
        license: 'CC0',
        creatorCredit: null,
        fileRef: 'models/pikachu.stl', // non-http = local fixture file, passes the file gate
        filamentMaterial: 'PLA',
        filamentColor: 'Black',
        estGrams: 30.0,
    ));

    $this->artisan('catalogue:pull-3d', ['query' => 'phone stand', '--source' => 'thingiverse'])
        ->assertSuccessful();

    $product = Product::query()->where('name', 'Pikachu phone stand')->first();

    expect($product)->not->toBeNull()
        ->and($product->ip_flagged)->toBeTrue()
        ->and($product->ip_flag_reason)->toBe('blocklist:pikachu')
        ->and($product->publish_state->value)->not->toBe('CANNOT_PUBLISH');
});

it('brings a licence-blocked model in for review instead of deleting it', function (): void {
    // Owner decision: any licence with a valid model is brought IN and held for
    // a staff licence decision (no longer hard-deleted). It never auto-publishes.
    Http::fake([
        'api.thingiverse.com/search/*' => Http::response(['hits' => [['id' => 988]]], 200),
    ]);

    app(StubModel3dApiClient::class)->with(new Model3dData(
        source: Model3dSource::Thingiverse,
        sourceId: '988',
        name: 'NC licensed vase',
        license: 'BLOCKED',
        creatorCredit: null,
        fileRef: 'models/vase.stl',
        filamentMaterial: 'PLA',
        filamentColor: 'Black',
        estGrams: 30.0,
    ));

    $this->artisan('catalogue:pull-3d', ['query' => 'vase', '--source' => 'thingiverse'])
        ->assertSuccessful();

    $product = Product::query()->where('name', 'NC licensed vase')->first();
    expect($product)->not->toBeNull()
        ->and($product->publish_state->value)->toBe('READY_TO_APPROVE')
        ->and($product->cannot_publish_reasons)->toContain('license_review');
});
