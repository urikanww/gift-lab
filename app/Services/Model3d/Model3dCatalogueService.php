<?php

declare(strict_types=1);

namespace App\Services\Model3d;

use App\Enums\License;
use App\Enums\PrintMethod;
use App\Enums\ProductClass;
use App\Enums\PublishState;
use App\Models\Model3D;
use App\Models\PricingConfig;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * 3D model catalogue lifecycle (spec 6.5). Ingest a model, gate publication on
 * its licence (only CC0 / CC_BY / OWNED are commercial-OK; CC_BY must carry
 * creator credit; anything else is blocked), and mirror the decision onto a
 * MODEL_3D catalogue Product that carries the filament spec for procurement.
 */
final class Model3dCatalogueService
{
    /**
     * @return array{model: Model3D, product: Product}
     */
    public function ingest(Model3dData $data): array
    {
        return DB::transaction(function () use ($data): array {
            $license = License::tryFrom($data->license) ?? License::Blocked;

            $model = Model3D::where('source', $data->source->value)
                ->where('source_id', $data->sourceId)
                ->first()
                ?? new Model3D(['source' => $data->source->value, 'source_id' => $data->sourceId]);

            $model->license = $license;
            $model->creator_credit = $data->creatorCredit;
            $model->file_ref = $data->fileRef;

            [$publishState, $reasons] = $this->gate($license, $data->creatorCredit);

            $model->publish_state = $publishState;
            $model->cannot_publish_reasons = $reasons;
            $model->save();

            $product = Product::where('model3d_id', $model->id)->first()
                ?? new Product(['class' => ProductClass::Model3d->value]);

            $product->class = ProductClass::Model3d;
            $product->model3d_id = $model->id;
            $product->name = $data->name;
            $product->image_url = $data->imageUrl;
            $product->description = $data->description;
            $product->base_cost = 0; // cost is filament + print, priced dynamically
            $product->print_method = PrintMethod::Fdm;
            $product->stock_mode = 'MAKE_TO_ORDER';
            $product->is_printable = true;
            $product->license = $license;
            $product->creator_credit = $data->creatorCredit;
            $product->model_file_ref = $data->fileRef;
            $product->filament_material = $data->filamentMaterial;
            $product->filament_color = $data->filamentColor;
            $product->est_grams = $data->estGrams;
            $product->publish_state = $publishState;
            $product->cannot_publish_reasons = $reasons;
            $product->save();

            return ['model' => $model, 'product' => $product];
        });
    }

    /**
     * @return array{0: PublishState, 1: array<int, string>|null}
     */
    private function gate(License $license, ?string $creatorCredit): array
    {
        if (! $license->isCommercialOk()) {
            return [PublishState::CannotPublish, ['license_blocked']];
        }

        if ($license->requiresCreatorCredit() && ($creatorCredit === null || $creatorCredit === '')) {
            return [PublishState::CannotPublish, ['missing_credit']];
        }

        $autoPublish = (bool) PricingConfig::value('catalogue', 'auto_publish', false);

        return [$autoPublish ? PublishState::Published : PublishState::ReadyToApprove, null];
    }
}
