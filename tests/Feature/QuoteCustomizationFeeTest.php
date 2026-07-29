<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Quote;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * LT16: the personalisation fee is folded into subtotal but has no line, so the
 * item rows visibly sum to less than subtotal. The resource surfaces the
 * aggregate fee (subtotal - active line totals) so the client can reconcile it.
 */
it('exposes the personalisation fee baked into subtotal so the numbers reconcile (LT16)', function (): void {
    $company = Company::factory()->create();
    $staff = User::factory()->staffAdmin()->create();
    $product = Product::factory()->create(['class' => 'CORE']);

    // subtotal 205 with a single 30 x 6.00 = 180 line → 25 fee folded in.
    $quote = Quote::factory()->create([
        'company_id' => $company->id,
        'state' => 'SENT',
        'subtotal' => 205.00,
        'total' => 205.00,
    ]);
    LineItem::factory()->create([
        'quote_id' => $quote->id, 'product_id' => $product->id,
        'unit_price' => 6.00, 'qty' => 30,
    ]);

    Sanctum::actingAs($staff);
    $res = $this->getJson("/api/quotes/{$quote->id}")->assertOk();

    expect($res->json('data.customization_fee'))->toBe('25.00');
});

it('reports a zero personalisation fee when no line carries one (LT16)', function (): void {
    $company = Company::factory()->create();
    $staff = User::factory()->staffAdmin()->create();
    $product = Product::factory()->create(['class' => 'CORE']);

    $quote = Quote::factory()->create([
        'company_id' => $company->id, 'state' => 'SENT',
        'subtotal' => 180.00, 'total' => 180.00,
    ]);
    LineItem::factory()->create([
        'quote_id' => $quote->id, 'product_id' => $product->id,
        'unit_price' => 6.00, 'qty' => 30,
    ]);

    Sanctum::actingAs($staff);
    $res = $this->getJson("/api/quotes/{$quote->id}")->assertOk();

    expect($res->json('data.customization_fee'))->toBe('0.00');
});
