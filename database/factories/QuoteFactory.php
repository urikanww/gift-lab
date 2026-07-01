<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use App\Models\Quote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Quote>
 */
class QuoteFactory extends Factory
{
    protected $model = Quote::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'state' => 'DRAFT',
            'currency' => 'SGD',
            'subtotal' => 0,
            'delivery' => 0,
            'total' => 0,
            'price_snapshot_at' => null,
            'amendment_log' => null,
            'notes' => null,
            'created_by' => null,
            'amended_by' => null,
        ];
    }

    public function sent(): static
    {
        return $this->state(fn (): array => [
            'state' => 'SENT',
            'price_snapshot_at' => now(),
        ]);
    }

    public function proofApproved(): static
    {
        return $this->state(fn (): array => [
            'state' => 'PROOF_APPROVED',
            'price_snapshot_at' => now(),
        ]);
    }
}
