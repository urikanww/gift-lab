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

it('brings a CC_BY item in without credit but holds it for licence review', function (): void {
    // Owner decision: any licence with a valid model is brought IN for staff
    // review. A CC_BY item missing its attribution is no longer hard-blocked -
    // it reaches READY_TO_APPROVE flagged license_review, and staff add credit
    // (or consciously publish).
    ['model' => $withCredit] = $this->service->ingest(modelData('CC_BY', 'Jane Maker'));
    ['model' => $noCredit] = $this->service->ingest(modelData('CC_BY', null));

    expect($withCredit->publish_state->value)->toBe('READY_TO_APPROVE')
        ->and($withCredit->cannot_publish_reasons)->toBeNull()
        ->and($noCredit->publish_state->value)->toBe('READY_TO_APPROVE')
        ->and($noCredit->cannot_publish_reasons)->toContain('license_review');
});

it('brings a blocked-licence item in for review instead of deleting it', function (): void {
    // A blocked/unknown licence with a valid model is now brought in for staff
    // review rather than blocked outright (owner decision) - but flagged
    // license_review so it never auto-publishes.
    ['model' => $model, 'product' => $product] = $this->service->ingest(modelData('BLOCKED', null));

    expect($model->publish_state->value)->toBe('READY_TO_APPROVE')
        ->and($model->cannot_publish_reasons)->toContain('license_review')
        ->and($product->publish_state->value)->toBe('READY_TO_APPROVE');
});

it('holds a Share-Alike item for licence review when it carries no credit', function (): void {
    ['model' => $withCredit] = $this->service->ingest(modelData('CC_BY_SA', 'Jane Maker'));
    ['model' => $noCredit] = $this->service->ingest(modelData('CC_BY_SA', null));

    // SA is attribution-bound like CC-BY; missing credit → licence review, not a block.
    expect($withCredit->publish_state->value)->toBe('READY_TO_APPROVE')
        ->and($withCredit->cannot_publish_reasons)->toBeNull()
        ->and($noCredit->publish_state->value)->toBe('READY_TO_APPROVE')
        ->and($noCredit->cannot_publish_reasons)->toContain('license_review');
});

it('skips a Thingiverse listing that ships no 3D model until one can be pulled', function (): void {
    // Thingiverse has a download API: a listing that exposes no printable file
    // genuinely has no model → held as awaiting_model_file (skip), retried on
    // the next resync when a model may appear.
    ['product' => $product] = $this->service->ingest(
        modelData('CC0', null, fileRef: 'https://www.thingiverse.com/thing:5'),
    );

    expect($product->publish_state->value)->toBe('CANNOT_PUBLISH')
        ->and($product->cannot_publish_reasons)->toContain('awaiting_model_file')
        ->and((bool) $product->is_printable)->toBeFalse();
});

it('holds a Cults3D item with no download API for manual file attach', function (): void {
    // Cults3D has no download API: a missing file means "staff attach manually",
    // not "no model exists" - so it holds on missing_model_file, not skip.
    ['product' => $product] = $this->service->ingest(new Model3dData(
        source: Model3dSource::Cults3d,
        sourceId: 'CULTS-'.uniqid(),
        name: 'Cults Widget',
        license: 'CC0',
        creatorCredit: null,
        fileRef: 'https://cults3d.com/thing/widget',
        filamentMaterial: 'PLA',
        filamentColor: 'Black',
        estGrams: 40.0,
    ));

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
