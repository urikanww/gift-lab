<?php

declare(strict_types=1);

use App\Enums\Model3dSource;
use App\Services\Model3d\Model3dCatalogueService;
use App\Services\Model3d\Model3dData;

beforeEach(function (): void {
    seedPricing();
    $this->service = app(Model3dCatalogueService::class);
});

function modelData(string $license, ?string $credit): Model3dData
{
    return new Model3dData(
        source: Model3dSource::Thingiverse,
        sourceId: 'THING-'.uniqid(),
        name: 'Desk Widget',
        license: $license,
        creatorCredit: $credit,
        fileRef: 'models/widget.stl',
        filamentMaterial: 'PLA',
        filamentColor: 'Black',
        estGrams: 40.0,
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
