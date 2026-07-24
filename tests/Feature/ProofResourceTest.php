<?php

declare(strict_types=1);

use App\Http\Resources\ProofResource;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Proof;
use App\Models\Quote;

it('exposes the line item id and product name on a serialized proof', function (): void {
    $quote = Quote::factory()->create();
    $product = Product::factory()->create(['name' => 'Baseball Cap']);
    $line = LineItem::factory()->create(['quote_id' => $quote->id, 'product_id' => $product->id, 'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/x.png']]);
    $proof = Proof::factory()->forLine($line)->create();

    $array = (new ProofResource($proof->loadMissing('lineItem.product')))->toArray(request());

    expect($array['line_item_id'])->toBe($line->id)
        ->and($array['product_name'])->toBe('Baseball Cap');
});
