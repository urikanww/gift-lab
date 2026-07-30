<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Quote;
use App\Models\Shipment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shipment>
 */
class ShipmentFactory extends Factory
{
    protected $model = Shipment::class;

    public function definition(): array
    {
        return [
            'quote_id' => Quote::factory(),
            'consignment_ref' => null,
            'carrier' => null,
            'label_url' => null,
            'last_courier_status' => null,
            'last_courier_status_at' => null,
            'delivered_at' => null,
        ];
    }
}
