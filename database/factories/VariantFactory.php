<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Variant>
 */
class VariantFactory extends Factory
{
    protected $model = Variant::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'attributes' => [
                'color' => $this->faker->safeColorName(),
                'size' => $this->faker->randomElement(['S', 'M', 'L']),
                'material' => $this->faker->randomElement(['Ceramic', 'Steel', 'Bamboo']),
            ],
            'sku' => strtoupper($this->faker->unique()->bothify('SKU-####-???')),
            'stock_on_hand' => $this->faker->numberBetween(0, 200),
            'reorder_threshold' => 20,
            'price_delta' => $this->faker->randomFloat(2, 0, 5),
            'currency' => 'SGD',
        ];
    }

    public function outOfStock(): static
    {
        return $this->state(fn (): array => ['stock_on_hand' => 0]);
    }
}
