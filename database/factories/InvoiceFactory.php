<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\Quote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        return [
            'quote_id' => Quote::factory(),
            'po_ref' => 'PO-'.$this->faker->unique()->numerify('######'),
            'payment_state' => 'UNPAID',
            'amount' => 0,
            'amount_paid' => null,
        ];
    }
}
