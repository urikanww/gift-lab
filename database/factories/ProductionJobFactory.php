<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\JobState;
use App\Enums\JobTrack;
use App\Models\ProductionJob;
use App\Models\Quote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductionJob>
 */
class ProductionJobFactory extends Factory
{
    protected $model = ProductionJob::class;

    public function definition(): array
    {
        return [
            'quote_id' => Quote::factory(),
            'track' => JobTrack::Uv,
            'state' => JobState::Ready,
            'ready_at' => now(),
            'qty' => 1,
        ];
    }
}
