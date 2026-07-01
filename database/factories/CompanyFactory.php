<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'registration_no' => strtoupper($this->faker->bothify('########?')),
            'billing_email' => $this->faker->companyEmail(),
            'phone' => $this->faker->phoneNumber(),
            'address' => $this->faker->address(),
            'default_terms' => 'NET30',
            'status' => 'ACTIVE',
            'created_by' => null,
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => ['status' => 'SUSPENDED']);
    }
}
