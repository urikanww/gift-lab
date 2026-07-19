<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SavedAddress;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavedAddress>
 */
class SavedAddressFactory extends Factory
{
    protected $model = SavedAddress::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'label' => $this->faker->randomElement(['Office', 'Warehouse', 'Home']),
            'recipient_name' => $this->faker->name(),
            'phone' => '+6591234567',
            'line1' => $this->faker->streetAddress(),
            'city' => 'Singapore',
            'postal_code' => (string) $this->faker->numberBetween(100000, 999999),
            'country' => 'SG',
        ];
    }
}
