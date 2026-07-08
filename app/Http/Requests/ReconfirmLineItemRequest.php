<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\LineItem;
use App\Services\PricingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Staff resolves a line item stuck in AWAITING_RECONFIRM after a failed stock/
 * price re-check (spec 5.2): amend (re-procure at new qty/price), approve the
 * jumped price, or drop the line. One failed line never kills the others.
 */
class ReconfirmLineItemRequest extends FormRequest
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
            'action' => ['required', 'string', 'in:amend,approve,drop'],
            'qty' => ['nullable', 'integer', 'min:1', 'max:100000', 'required_if:action,amend'],
            'unit_price' => ['nullable', 'numeric', 'min:0', 'required_if:action,amend'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // The reconfirmation amend is the same financial act as a pre-send
            // amend (spec 6.2) - the margin floor applies identically. Without
            // this, the highest-risk path (PRICE_JUMPED/QTY_SHORT re-quote) was
            // the one place a unit price could fall below landed cost + floor.
            if ($this->input('action') !== 'amend' || $this->input('unit_price') === null) {
                return;
            }

            /** @var LineItem|null $line */
            $line = $this->route('lineItem');
            if (! $line instanceof LineItem) {
                return;
            }

            /** @var PricingService $pricing */
            $pricing = app(PricingService::class);
            $line->loadMissing('product', 'variant');
            $landedCost = $pricing->landedCost($line->product, $line->variant);

            if (! $pricing->isAboveMarginFloor((float) $this->input('unit_price'), $landedCost)) {
                $validator->errors()->add(
                    'unit_price',
                    'Unit price is below the configured margin floor over landed cost.'
                );
            }
        });
    }
}
