<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProofState;
use App\Models\LineItem;
use App\Models\Proof;
use App\Models\Quote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Proof>
 */
class ProofFactory extends Factory
{
    protected $model = Proof::class;

    public function definition(): array
    {
        return [
            'quote_id' => Quote::factory(),
            'line_item_id' => LineItem::factory(),
            'version' => 1,
            'artwork_version_ref' => 'proofs/'.$this->faker->uuid().'.pdf',
            'state' => ProofState::Draft->value,
            'approved_by' => null,
            'approved_at' => null,
            'notes' => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (): array => [
            'state' => 'APPROVED',
            'approved_at' => now(),
        ]);
    }
}
