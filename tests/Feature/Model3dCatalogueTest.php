<?php

declare(strict_types=1);

use App\Enums\Model3dSource;
use App\Models\PricingConfig;
use App\Services\Model3d\Model3dCatalogueService;
use App\Services\Model3d\Model3dData;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    seedPricing();
    $this->service = app(Model3dCatalogueService::class);
});

function modelData(string $license, ?string $credit, ?string $fileRef = 'models/widget.stl', ?string $downloadUrl = null): Model3dData
{
    return new Model3dData(
        source: Model3dSource::Thingiverse,
        sourceId: 'THING-'.uniqid(),
        name: 'Desk Widget',
        license: $license,
        creatorCredit: $credit,
        fileRef: $fileRef,
        filamentMaterial: 'PLA',
        filamentColor: 'Black',
        estGrams: 40.0,
        downloadUrl: $downloadUrl,
        downloadFileName: $downloadUrl !== null ? 'widget.stl' : null,
    );
}

function enableAutoPublish(): void
{
    PricingConfig::updateOrCreate(
        ['group' => 'catalogue', 'key' => 'auto_publish'],
        ['value' => true, 'label' => 'Auto publish', 'is_money' => false, 'currency' => 'SGD'],
    );
}

it('publishes CC0 to READY_TO_APPROVE and creates a MODEL_3D product', function (): void {
    ['model' => $model, 'product' => $product] = $this->service->ingest(modelData('CC0', null));

    expect($model->publish_state->value)->toBe('READY_TO_APPROVE')
        ->and($product->class->value)->toBe('MODEL_3D')
        ->and($product->filament_material)->toBe('PLA')
        ->and((float) $product->est_grams)->toBe(40.0);
});

it('publishes CC_BY only with creator credit', function (): void {
    ['model' => $withCredit] = $this->service->ingest(modelData('CC_BY', 'Jane Maker'));
    ['model' => $noCredit] = $this->service->ingest(modelData('CC_BY', null));

    expect($withCredit->publish_state->value)->toBe('READY_TO_APPROVE')
        ->and($noCredit->publish_state->value)->toBe('CANNOT_PUBLISH')
        ->and($noCredit->cannot_publish_reasons)->toContain('missing_credit');
});

it('blocks a non-commercial licence', function (): void {
    ['model' => $model, 'product' => $product] = $this->service->ingest(modelData('BLOCKED', null));

    expect($model->publish_state->value)->toBe('CANNOT_PUBLISH')
        ->and($model->cannot_publish_reasons)->toContain('license_blocked')
        ->and($product->publish_state->value)->toBe('CANNOT_PUBLISH');
});

it('clears a Share-Alike item through the gate when it carries creator credit', function (): void {
    ['model' => $withCredit] = $this->service->ingest(modelData('CC_BY_SA', 'Jane Maker'));
    ['model' => $noCredit] = $this->service->ingest(modelData('CC_BY_SA', null));

    // SA is now commercial-OK; still attribution-bound like CC-BY.
    expect($withCredit->publish_state->value)->toBe('READY_TO_APPROVE')
        ->and($noCredit->publish_state->value)->toBe('CANNOT_PUBLISH')
        ->and($noCredit->cannot_publish_reasons)->toContain('missing_credit');
});

it('blocks an item with no locally producible model file', function (): void {
    // Live-source shape: fileRef is the source page URL, no download API.
    ['product' => $product] = $this->service->ingest(
        modelData('CC0', null, fileRef: 'https://cults3d.com/thing/widget'),
    );

    expect($product->publish_state->value)->toBe('CANNOT_PUBLISH')
        ->and($product->cannot_publish_reasons)->toContain('missing_model_file')
        ->and((bool) $product->is_printable)->toBeFalse();
});

it('downloads the model file at ingest and stores our own copy', function (): void {
    Storage::fake('local');
    Http::fake(['files.example.com/*' => Http::response('solid widget…', 200)]);

    ['product' => $product] = $this->service->ingest(modelData(
        'CC0',
        null,
        fileRef: 'https://www.thingiverse.com/thing:99',
        downloadUrl: 'https://files.example.com/download:99',
    ));

    expect($product->publish_state->value)->toBe('READY_TO_APPROVE')
        ->and($product->model_file_ref)->toStartWith('models3d/')
        ->and(Storage::disk('local')->exists($product->model_file_ref))->toBeTrue();
});

it('holds unverified estimates out of auto-publish', function (): void {
    enableAutoPublish();

    ['product' => $product] = $this->service->ingest(modelData('CC0', null));

    expect($product->publish_state->value)->toBe('READY_TO_APPROVE')
        ->and($product->cannot_publish_reasons)->toContain('estimates_unverified');
});

it('auto-publishes once estimates are verified', function (): void {
    enableAutoPublish();

    ['product' => $product] = $this->service->ingest(modelData('CC0', null));
    $product = $this->service->verifyEstimates($product, 'PETG', 'White', 62.5);

    expect($product->publish_state->value)->toBe('PUBLISHED')
        ->and((bool) $product->estimates_verified)->toBeTrue()
        ->and((float) $product->est_grams)->toBe(62.5);
});

it('refuses staff publish while estimates are unverified', function (): void {
    ['product' => $product] = $this->service->ingest(modelData('CC0', null));

    $product = $this->service->publish($product);

    expect($product->publish_state->value)->toBe('READY_TO_APPROVE')
        ->and($product->cannot_publish_reasons)->toContain('estimates_unverified');
});

it('publishes via staff approval after estimates are verified', function (): void {
    ['product' => $product] = $this->service->ingest(modelData('CC0', null));

    $product = $this->service->verifyEstimates($product, 'PLA', 'Black', 40.0);
    $product = $this->service->publish($product);

    expect($product->publish_state->value)->toBe('PUBLISHED');
});
