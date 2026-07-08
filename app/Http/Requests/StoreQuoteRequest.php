<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ProductClass;
use App\Enums\PublishState;
use App\Models\PricingConfig;
use App\Models\Product;
use App\Models\Variant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
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
        // The authoritative tier set is the superadmin-configured surcharge
        // table (spec principle 5) - an out-of-set size must be rejected, not
        // silently priced at zero surcharge (Pass 2 F1 / audit D11).
        $tiers = array_keys((array) PricingConfig::value('fee', 'customization_by_size', []));
        if ($tiers === []) {
            $tiers = ['S', 'M', 'L'];
        }

        return [
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
            // Buyer's "need it by" deadline (optional); can't be in the past.
            'needed_by' => ['nullable', 'date', 'after_or_equal:today'],
            // Client-generated replay token: the same cart re-submitted (double
            // click / network retry) returns the original quote (audit A12).
            'idempotency_key' => ['nullable', 'string', 'max:64'],
            'line_items' => ['required', 'array', 'min:1'],
            'line_items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'line_items.*.variant_id' => ['nullable', 'integer', 'exists:variants,id'],
            'line_items.*.qty' => ['required', 'integer', 'min:1', 'max:100000'],
            'line_items.*.customization' => ['nullable', 'array'],
            'line_items.*.customization.logo_size' => ['nullable', 'string', Rule::in($tiers)],
            // Storage key issued by POST /uploads/artwork: a single "artwork/"
            // segment + generated filename. Anything else (traversal, foreign
            // prefixes) is rejected before it can reach the print pipeline
            // (Pass 2 F2 / audit C15). Existence is checked in withValidator.
            'line_items.*.customization.artwork_ref' => ['nullable', 'string', 'max:2048', 'regex:#^artwork/[A-Za-z0-9_\-]+\.[A-Za-z0-9]{1,10}$#'],
            // MODEL_3D lines additionally carry a UV-flattened production decal
            // (the file the printer/jig consumes, distinct from the proof
            // mockup above). It reaches the print pipeline too, so it gets the
            // same path guard - a "artwork/" key issued by POST /uploads/artwork.
            // Existence is checked in withValidator.
            'line_items.*.customization.print_file_ref' => ['nullable', 'string', 'max:2048', 'regex:#^artwork/[A-Za-z0-9_\-]+\.[A-Za-z0-9]{1,10}$#'],
            // Machine-readable placement record captured by the designer
            // (position/size/rotation + export pixel mapping, audit C12).
            'line_items.*.customization.layout' => ['nullable', 'array'],
            // Name/text personalisation content (spec 6.1, audit D9) - the
            // rendered layer ships inside the artwork; this is the recorded
            // source text, priced per unit via fee.customization_per_unit.
            'line_items.*.customization.text' => ['nullable', 'string', 'max:500'],
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
            // line - one query per cart line, compounding under bulk carts).
            $lineItems = (array) $this->input('line_items', []);

            $productIds = array_values(array_filter(array_map(
                static fn ($line): ?int => isset($line['product_id']) ? (int) $line['product_id'] : null,
                $lineItems,
            ), static fn (?int $id): bool => $id !== null));

            $products = $productIds === []
                ? collect()
                : Product::query()->whereIn('id', $productIds)->get()->keyBy('id');

            // Batch-load referenced variants once for the linkage check below.
            $variantIds = array_values(array_filter(array_map(
                static fn ($line): ?int => isset($line['variant_id']) ? (int) $line['variant_id'] : null,
                $lineItems,
            ), static fn (?int $id): bool => $id !== null));

            $variants = $variantIds === []
                ? collect()
                : Variant::query()->whereIn('id', $variantIds)->get()->keyBy('id');

            $artworkDisk = Storage::disk((string) config('filesystems.artwork_disk'));

            // CORE products with no variants at all are structurally
            // unprocurable (CoreProcurement sources blanks from variant
            // stock), so quoting them only manufactures a stuck line
            // (audit E4 interim guard - "Alpha Mug" case).
            $productIdsWithVariants = $productIds === []
                ? collect()
                : Variant::query()->whereIn('product_id', $productIds)->distinct()->pluck('product_id')->flip();

            foreach ($lineItems as $index => $line) {
                $productId = isset($line['product_id']) ? (int) $line['product_id'] : null;
                if ($productId === null) {
                    continue;
                }

                $product = $products->get($productId);
                if ($product !== null && $product->publish_state !== PublishState::Published) {
                    $validator->errors()->add("line_items.{$index}.product_id", 'Product is not available.');
                }

                if ($product !== null
                    && $product->class === ProductClass::Core
                    && ! $productIdsWithVariants->has($productId)
                ) {
                    $validator->errors()->add(
                        "line_items.{$index}.product_id",
                        'This product cannot be ordered yet - no variants are configured.'
                    );
                }

                // A line's variant must belong to that line's product - a
                // foreign variant mis-prices the line and procurement would
                // draw down another product's stock (Pass 2 F3 / audit B9).
                $variantId = isset($line['variant_id']) ? (int) $line['variant_id'] : null;
                if ($variantId !== null) {
                    $variant = $variants->get($variantId);
                    if ($variant !== null && $variant->product_id !== $productId) {
                        $validator->errors()->add(
                            "line_items.{$index}.variant_id",
                            'Variant does not belong to the selected product.'
                        );
                    }
                }

                // The ref must resolve to a real uploaded file - format alone
                // still lets a guessed/foreign key through to the floor. Both
                // the proof artwork and the 3D print file are guarded the same.
                foreach (['artwork_ref', 'print_file_ref'] as $refKey) {
                    $ref = $line['customization'][$refKey] ?? null;
                    if (is_string($ref)
                        && preg_match('#^artwork/[A-Za-z0-9_\-]+\.[A-Za-z0-9]{1,10}$#', $ref) === 1
                        && ! $artworkDisk->exists($ref)
                    ) {
                        $validator->errors()->add(
                            "line_items.{$index}.customization.{$refKey}",
                            'Artwork reference does not resolve to an uploaded file.'
                        );
                    }
                }
            }
        });
    }
}
