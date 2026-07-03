<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LineItem;
use App\Models\Product;
use App\Models\Quote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LineItem>
 */
class LineItemFactory extends Factory
{
    protected $model = LineItem::class;

    public function definition(): array
    {
        return [
            'quote_id' => Quote::factory(),
            'job_id' => null,
            'product_id' => Product::factory(),
            'variant_id' => null,
            'qty' => $this->faker->numberBetween(1, 100),
            'unit_price' => $this->faker->randomFloat(2, 2, 50),
            'currency' => 'SGD',
            'customization' => [
                'logo_size' => 'M',
                'artwork_ref' => null,
            ],
            'line_state' => 'PENDING',
            'procured_qty' => null,
            'procured_price' => null,
            'frozen_snapshot' => null,
            'lead_time_days' => $this->faker->numberBetween(3, 21),
        ];
    }

    public function ready(): static
    {
        return $this->state(fn (): array => ['line_state' => 'READY']);
    }

    public function awaitingReconfirm(): static
    {
        return $this->state(fn (): array => ['line_state' => 'AWAITING_RECONFIRM']);
    }
}
