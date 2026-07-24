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
            // Bind the proof to a customized line on ITS OWN quote, so recomputeProofState
            // (which matches proofs to counting lines by quote+line) sees a real target.
            'line_item_id' => fn (array $attributes) => LineItem::factory()->create([
                'quote_id' => $attributes['quote_id'],
                'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/factory.png'],
                'line_state' => 'PENDING',
            ])->id,
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

    /** Attach this proof to an existing line (and its quote). */
    public function forLine(LineItem $line): static
    {
        return $this->state(fn (): array => [
            'quote_id' => $line->quote_id,
            'line_item_id' => $line->id,
        ]);
    }
}
