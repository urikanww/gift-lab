<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\LineItem;
use App\Services\PricingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Staff amends quote line prices/quantities before send (spec 6.2). Every
 * amendment is logged (who/what/when) and no amended unit price may fall below
 * the configured margin floor over landed cost.
 */
class AmendQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isStaff() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'delivery' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.id' => ['required', 'integer', 'exists:line_items,id'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.qty' => ['required', 'integer', 'min:1', 'max:100000'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var PricingService $pricing */
            $pricing = app(PricingService::class);
            $quoteId = (int) $this->route('quote')?->id;

            foreach ((array) $this->input('lines', []) as $index => $lineInput) {
                $line = LineItem::with('product', 'variant')->find($lineInput['id'] ?? null);

                if ($line === null) {
                    continue;
                }

                // Amended lines must belong to the quote being amended.
                if ($line->quote_id !== $quoteId) {
                    $validator->errors()->add("lines.{$index}.id", 'Line item does not belong to this quote.');

                    continue;
                }

                // Class-aware landed cost — MODEL_3D has no blank; its cost is
                // filament + machine time (PricingService::landedCost).
                $landedCost = $pricing->landedCost($line->product, $line->variant);

                if (! $pricing->isAboveMarginFloor((float) $lineInput['unit_price'], $landedCost)) {
                    $validator->errors()->add(
                        "lines.{$index}.unit_price",
                        'Unit price is below the configured margin floor over landed cost.'
                    );
                }
            }
        });
    }
}
