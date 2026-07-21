<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\LineItem;
use App\Models\Product;
use App\Models\Variant;
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
            // Optional: the editor submits only the lines it actually changed
            // (the service merges over the rest), so reducing delivery because
            // the goods stack, or removing a line, is a valid amendment with no
            // `lines` at all. Requiring min:1 here also forced every untouched
            // line back through the margin floor, which would make an order
            // quoted under an older floor permanently unsaveable.
            'lines' => ['nullable', 'array'],
            // Absent id = a line being added. Present id = an existing line
            // being re-priced or re-quantified.
            'lines.*.id' => ['nullable', 'integer', 'exists:line_items,id'],
            'lines.*.product_id' => ['required_without:lines.*.id', 'nullable', 'integer', 'exists:products,id'],
            'lines.*.variant_id' => ['nullable', 'integer', 'exists:variants,id'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.qty' => ['required', 'integer', 'min:1', 'max:100000'],
            // Removal is explicit: omitting a line from `lines` means "leave it
            // alone", so it can never be the way an order loses a line.
            'removed_line_ids' => ['nullable', 'array'],
            'removed_line_ids.*' => ['integer', 'exists:line_items,id'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var PricingService $pricing */
            $pricing = app(PricingService::class);
            $quoteId = (int) $this->route('quote')?->id;

            foreach ((array) $this->input('lines', []) as $index => $lineInput) {
                if (($lineInput['id'] ?? null) !== null) {
                    $line = LineItem::with('product', 'variant')->find($lineInput['id']);

                    if ($line === null) {
                        continue;
                    }

                    // Amended lines must belong to the quote being amended.
                    if ($line->quote_id !== $quoteId) {
                        $validator->errors()->add("lines.{$index}.id", 'Line item does not belong to this quote.');

                        continue;
                    }

                    $product = $line->product;
                    $variant = $line->variant;
                } else {
                    // Added line: product and variant come from the payload, so
                    // the margin floor is checked against what is being added
                    // rather than an existing row.
                    $product = Product::find($lineInput['product_id'] ?? null);
                    $variant = Variant::find($lineInput['variant_id'] ?? null);

                    if ($product === null) {
                        continue;
                    }

                    if ($variant !== null && $variant->product_id !== $product->id) {
                        $validator->errors()->add(
                            "lines.{$index}.variant_id",
                            'Variant does not belong to the selected product.'
                        );

                        continue;
                    }
                }

                // Class-aware landed cost - MODEL_3D has no blank; its cost is
                // filament + machine time (PricingService::landedCost).
                $landedCost = $pricing->landedCost($product, $variant);

                if (! $pricing->isAboveMarginFloor((float) $lineInput['unit_price'], $landedCost)) {
                    $validator->errors()->add(
                        "lines.{$index}.unit_price",
                        'Unit price is below the configured margin floor over landed cost.'
                    );
                }
            }

            $this->validateRemovals($validator, $quoteId);
        });
    }

    /**
     * Removals must belong to this quote, and must not empty it. An order with
     * no lines cannot be produced or priced, and nothing downstream closes it -
     * a wholly-emptied quote would simply sit there.
     */
    private function validateRemovals(Validator $validator, int $quoteId): void
    {
        $removedIds = array_map('intval', (array) $this->input('removed_line_ids', []));

        if ($removedIds === []) {
            return;
        }

        $ownedIds = LineItem::query()
            ->where('quote_id', $quoteId)
            ->pluck('id')
            ->all();

        $foreign = array_diff($removedIds, $ownedIds);
        if ($foreign !== []) {
            $validator->errors()->add('removed_line_ids', 'One or more line items do not belong to this quote.');

            return;
        }

        $addedCount = 0;
        foreach ((array) $this->input('lines', []) as $lineInput) {
            if (($lineInput['id'] ?? null) === null) {
                $addedCount++;
            }
        }

        $survivors = count(array_diff($ownedIds, $removedIds)) + $addedCount;
        if ($survivors < 1) {
            $validator->errors()->add('removed_line_ids', 'A quote must keep at least one line item.');
        }
    }
}
