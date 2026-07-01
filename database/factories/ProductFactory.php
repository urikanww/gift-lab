<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'name' => ucwords($this->faker->words(2, true)),
            'description' => $this->faker->sentence(),
            'class' => 'CORE',
            'base_cost' => $this->faker->randomFloat(2, 2, 40),
            'currency' => 'SGD',
            'dimensions' => ['l' => 100, 'w' => 60, 'h' => 40, 'unit' => 'mm'],
            'weight' => $this->faker->randomFloat(3, 20, 500),
            'print_method' => 'UV',
            'publish_state' => 'PUBLISHED',
            'cannot_publish_reasons' => null,
            'stock_mode' => 'STOCKED',
            'image_url' => null,
            'is_printable' => true,
            'license' => null,
            'creator_credit' => null,
            'model_file_ref' => null,
            'created_by' => null,
        ];
    }

    public function scrapedUv(): static
    {
        return $this->state(fn (): array => [
            'class' => 'SCRAPED_UV',
            'stock_mode' => 'MAKE_TO_ORDER',
            'source_url' => $this->faker->url(),
            'source_product_id' => (string) $this->faker->randomNumber(8),
            'stock_estimate' => $this->faker->numberBetween(0, 500),
        ]);
    }

    public function model3d(): static
    {
        return $this->state(fn (): array => [
            'class' => 'MODEL_3D',
            'print_method' => 'FDM',
            'stock_mode' => 'MAKE_TO_ORDER',
            'license' => 'CC_BY',
            'creator_credit' => $this->faker->name(),
            'model_file_ref' => 'models/'.$this->faker->uuid().'.stl',
            'filament_material' => 'PLA',
            'filament_color' => 'Black',
            'est_grams' => $this->faker->randomFloat(3, 20, 300),
        ]);
    }
}
