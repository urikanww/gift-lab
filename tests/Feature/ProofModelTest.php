<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Proof;
use App\Models\Quote;

it('belongs to a line item', function (): void {
    $company = Company::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $company->id]);
    $product = Product::factory()->create();
    $line = LineItem::factory()->create(['quote_id' => $quote->id, 'product_id' => $product->id]);
    $proof = Proof::factory()->create(['quote_id' => $quote->id, 'line_item_id' => $line->id]);

    expect($proof->lineItem->id)->toBe($line->id);
});
