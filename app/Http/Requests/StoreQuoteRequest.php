<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\PublishState;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Buyer converts a designer cart into a draft quote (account required only at
 * this step, spec 6.1). Staff may also raise a quote on a company's behalf.
 */
class StoreQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && ($user->isStaff() || $user->company_id !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'line_items' => ['required', 'array', 'min:1'],
            'line_items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'line_items.*.variant_id' => ['nullable', 'integer', 'exists:variants,id'],
            'line_items.*.qty' => ['required', 'integer', 'min:1', 'max:100000'],
            'line_items.*.customization' => ['nullable', 'array'],
            'line_items.*.customization.logo_size' => ['nullable', 'string', 'max:20'],
            'line_items.*.customization.artwork_ref' => ['nullable', 'string', 'max:2048'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // A buyer may only quote for their own company (tenancy isolation).
            $user = $this->user();
            if ($user !== null && ! $user->isStaff() && (int) $this->input('company_id') !== $user->company_id) {
                $validator->errors()->add('company_id', 'You may only create quotes for your own company.');
            }

            // Every referenced product must be publicly published. Batch-load
            // all referenced products in a single query (was Product::find per
            // line — one query per cart line, compounding under bulk carts).
            $lineItems = (array) $this->input('line_items', []);

            $productIds = array_values(array_filter(array_map(
                static fn ($line): ?int => isset($line['product_id']) ? (int) $line['product_id'] : null,
                $lineItems,
            ), static fn (?int $id): bool => $id !== null));

            $products = $productIds === []
                ? collect()
                : Product::query()->whereIn('id', $productIds)->get()->keyBy('id');

            foreach ($lineItems as $index => $line) {
                $productId = isset($line['product_id']) ? (int) $line['product_id'] : null;
                if ($productId === null) {
                    continue;
                }

                $product = $products->get($productId);
                if ($product !== null && $product->publish_state !== PublishState::Published) {
                    $validator->errors()->add("line_items.{$index}.product_id", 'Product is not available.');
                }
            }
        });
    }
}
