<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Invoice;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Quote;
use App\Models\User;
use App\Services\QuoteService;
use App\Support\Permissions;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

beforeEach(function (): void {
    $this->superadmin = User::factory()->create(['role' => 'superadmin']);
    $this->staff = User::factory()->staffAdmin()->create();
    $this->company = Company::factory()->create();
    $this->buyer = User::factory()->create(['company_id' => $this->company->id, 'role' => 'buyer']);
});

// ---- resolver ------------------------------------------------------------

it('gives a superadmin every permission and never restricts them', function (): void {
    expect($this->superadmin->effectivePermissions())->toBe(Permissions::all())
        ->and($this->superadmin->hasPermission('quotes.edit'))->toBeTrue();
});

it('grandfathers a staff_admin to the operational default, not the sensitive sections', function (): void {
    expect($this->staff->permissions)->toBeNull()
        ->and($this->staff->hasPermission('production.manage'))->toBeTrue()
        // Sensitive Pricing/Users are NOT part of the grandfather default.
        ->and($this->staff->hasPermission('pricing.view'))->toBeFalse()
        ->and($this->staff->hasPermission('users.manage'))->toBeFalse()
        ->and($this->staff->effectivePermissions())->toBe(Permissions::defaults());
});

it('keeps the sensitive sections out of the default but in the full set', function (): void {
    expect(Permissions::defaults())->not->toContain('pricing.view')->not->toContain('users.manage')
        ->and(Permissions::all())->toContain('pricing.view')->toContain('users.manage')
        ->and(Permissions::isSensitive('pricing.manage'))->toBeTrue()
        ->and(Permissions::isSensitive('quotes.edit'))->toBeFalse();
});

it('restricts a staff_admin to exactly their granted set', function (): void {
    $this->staff->update(['permissions' => ['quotes.view', 'quotes.edit']]);

    expect($this->staff->hasPermission('quotes.edit'))->toBeTrue()
        ->and($this->staff->hasPermission('production.manage'))->toBeFalse()
        ->and($this->staff->effectivePermissions())->toBe(['quotes.view', 'quotes.edit']);
});

it('gives a buyer no console permissions', function (): void {
    expect($this->buyer->effectivePermissions())->toBe([])
        ->and($this->buyer->hasPermission('quotes.view'))->toBeFalse();
});

// ---- middleware enforcement ---------------------------------------------

it('403s a staff_admin who lacks the permission for a gated endpoint', function (): void {
    $this->staff->update(['permissions' => ['quotes.view']]); // no notifications.view
    Sanctum::actingAs($this->staff);

    getJson('/api/admin/notification-settings')->assertStatus(403);
});

it('lets a staff_admin through once granted the permission', function (): void {
    $this->staff->update(['permissions' => ['notifications.view']]);
    Sanctum::actingAs($this->staff);

    getJson('/api/admin/notification-settings')->assertOk();
});

it('lets a grandfathered staff_admin (no set) through', function (): void {
    Sanctum::actingAs($this->staff);

    getJson('/api/admin/notification-settings')->assertOk();
});

it('never restricts a superadmin at the middleware', function (): void {
    Sanctum::actingAs($this->superadmin);

    getJson('/api/admin/notification-settings')->assertOk();
});

// ---- admin management ----------------------------------------------------

it('lets a superadmin set a staff_admin allowlist', function (): void {
    Sanctum::actingAs($this->superadmin);

    patchJson("/api/admin/users/{$this->staff->id}", [
        'permissions' => ['quotes.view', 'quotes.edit'],
    ])->assertOk()->assertJsonPath('data.permissions', ['quotes.view', 'quotes.edit']);

    expect($this->staff->fresh()->permissions)->toBe(['quotes.view', 'quotes.edit']);
});

it('rejects an unknown permission key', function (): void {
    Sanctum::actingAs($this->superadmin);

    patchJson("/api/admin/users/{$this->staff->id}", [
        'permissions' => ['quotes.view', 'quotes.launch_rockets'],
    ])->assertStatus(422)->assertJsonValidationErrors('permissions.1');
});

it('clears any allowlist when a user is moved off staff_admin', function (): void {
    $this->staff->update(['permissions' => ['quotes.view']]);
    Sanctum::actingAs($this->superadmin);

    patchJson("/api/admin/users/{$this->staff->id}", ['role' => 'superadmin'])->assertOk();

    expect($this->staff->fresh()->permissions)->toBeNull();
});

it('reports whether the allowlist is editable per role', function (): void {
    Sanctum::actingAs($this->superadmin);

    getJson("/api/admin/users/{$this->staff->id}")->assertJsonPath('data.permissions_editable', true);
    getJson("/api/admin/users/{$this->superadmin->id}")->assertJsonPath('data.permissions_editable', false);
});

it('exposes effective permissions on the auth payload', function (): void {
    $this->staff->update(['permissions' => ['quotes.view']]);
    Sanctum::actingAs($this->staff);

    getJson('/api/user')->assertOk()->assertJsonPath('permissions', ['quotes.view']);
});

// ---- superadmin edits an order in any state -----------------------------

it('lets a superadmin amend a line on a non-draft order', function (): void {
    Sanctum::actingAs($this->superadmin);
    $product = Product::factory()->create(['base_cost' => 1]);
    $quote = Quote::factory()->create([
        'company_id' => $this->company->id, 'state' => 'CONFIRMED',
        'subtotal' => 40, 'delivery' => 5, 'total' => 45,
    ]);
    $line = LineItem::factory()->create([
        'quote_id' => $quote->id, 'product_id' => $product->id, 'unit_price' => 10, 'qty' => 4,
    ]);

    app(QuoteService::class)->amend($quote, [['id' => $line->id, 'unit_price' => 20, 'qty' => 4]], null, null, [], null, 'Corrected a mispriced line.');

    // 4 x 20 + 5 delivery
    expect((float) $quote->fresh()->total)->toBe(85.0);
});

it('still blocks a plain staff_admin from amending a non-draft order', function (): void {
    Sanctum::actingAs($this->staff);
    $product = Product::factory()->create(['base_cost' => 1]);
    $quote = Quote::factory()->create(['company_id' => $this->company->id, 'state' => 'CONFIRMED']);
    $line = LineItem::factory()->create([
        'quote_id' => $quote->id, 'product_id' => $product->id, 'unit_price' => 10, 'qty' => 4,
    ]);

    expect(fn () => app(QuoteService::class)->amend(
        $quote, [['id' => $line->id, 'unit_price' => 20, 'qty' => 4]], null, null, [], null, 'Trying to edit.'
    ))->toThrow(App\Exceptions\DomainRuleException::class);
});

it('re-anchors an already-issued invoice to the new total on a superadmin edit', function (): void {
    Sanctum::actingAs($this->superadmin);
    $product = Product::factory()->create(['base_cost' => 1]);
    $quote = Quote::factory()->create([
        'company_id' => $this->company->id, 'state' => 'CONFIRMED',
        'subtotal' => 40, 'delivery' => 5, 'total' => 45,
    ]);
    $line = LineItem::factory()->create([
        'quote_id' => $quote->id, 'product_id' => $product->id, 'unit_price' => 10, 'qty' => 4,
    ]);
    $invoice = Invoice::create([
        'quote_id' => $quote->id, 'po_ref' => 'PO-1', 'payment_state' => 'UNPAID',
        'amount' => 45, 'currency' => 'SGD', 'issued_at' => now(),
    ]);

    app(QuoteService::class)->amend($quote, [['id' => $line->id, 'unit_price' => 20, 'qty' => 4]], null, null, [], null, 'Corrected a mispriced line.');

    // Invoice follows the quote: 4 x 20 + 5 = 85.
    expect((float) $invoice->fresh()->amount)->toBe(85.0);
});

// The product sub-routes (model streams, parts, variants, images) are gated too,
// not just the main list/detail - so a restricted staff_admin cannot reach them
// directly even though the console nav hides the section.

it('403s a restricted staff_admin on a product write sub-route', function (): void {
    $this->staff->update(['permissions' => ['quotes.view']]); // no products.edit
    $product = Product::factory()->create();
    Sanctum::actingAs($this->staff);

    postJson("/api/admin/products/{$product->id}/variants", [])->assertStatus(403);
});

it('403s a restricted staff_admin on a product read sub-route', function (): void {
    $this->staff->update(['permissions' => ['quotes.view']]); // no products.view
    $product = Product::factory()->create();
    Sanctum::actingAs($this->staff);

    getJson("/api/admin/products/{$product->id}/model")->assertStatus(403);
});

it('lets a staff_admin granted products.edit past the sub-route gate', function (): void {
    // Granted the permission, so the 403 gate is cleared - whatever the endpoint
    // does next (validation/404), it is no longer an access refusal.
    $this->staff->update(['permissions' => ['products.edit']]);
    $product = Product::factory()->create();
    Sanctum::actingAs($this->staff);

    postJson("/api/admin/products/{$product->id}/variants", [])->assertStatus(422);
});

it('keeps a grandfathered staff_admin able to reach product sub-routes', function (): void {
    $product = Product::factory()->create();
    Sanctum::actingAs($this->staff); // null permissions = unrestricted

    postJson("/api/admin/products/{$product->id}/variants", [])->assertStatus(422);
});

// ---- sensitive sections: Pricing & Users -------------------------------

it('lets a staff_admin granted pricing.view read pricing but not edit it', function (): void {
    $this->staff->update(['permissions' => ['pricing.view']]);
    $config = App\Models\PricingConfig::query()->first()
        ?? App\Models\PricingConfig::create(['group' => 'g', 'key' => 'k', 'value' => 1, 'label' => 'L', 'is_money' => false]);
    Sanctum::actingAs($this->staff);

    getJson('/api/admin/pricing-configs')->assertOk();
    patchJson("/api/admin/pricing-configs/{$config->id}", ['value' => 2])->assertStatus(403);
});

it('blocks a grandfathered staff_admin from pricing entirely', function (): void {
    Sanctum::actingAs($this->staff);
    getJson('/api/admin/pricing-configs')->assertStatus(403);
});

it('lets a staff_admin granted users.view read users but not manage them', function (): void {
    $this->staff->update(['permissions' => ['users.view']]);
    Sanctum::actingAs($this->staff);

    getJson('/api/admin/users')->assertOk();
    postJson('/api/admin/users', [])->assertStatus(403);
});

it('blocks a grandfathered staff_admin from the users module', function (): void {
    Sanctum::actingAs($this->staff);
    getJson('/api/admin/users')->assertStatus(403);
});

// ---- escalation guards on a delegated Users manager ---------------------

it('stops a delegated users manager from promoting anyone to superadmin', function (): void {
    $this->staff->update(['permissions' => ['users.view', 'users.manage']]);
    $target = User::factory()->staffAdmin()->create();
    Sanctum::actingAs($this->staff);

    patchJson("/api/admin/users/{$target->id}", ['role' => 'superadmin'])
        ->assertStatus(422)->assertJsonFragment(['message' => 'Only a superadmin can grant the superadmin role.']);
});

it('stops a delegated users manager from creating a superadmin', function (): void {
    $this->staff->update(['permissions' => ['users.view', 'users.manage']]);
    Sanctum::actingAs($this->staff);

    postJson('/api/admin/users', [
        'name' => 'X', 'email' => 'x@y.test', 'password' => 'password123', 'role' => 'superadmin',
    ])->assertStatus(422);
});

it('stops a delegated users manager from editing their own access', function (): void {
    $this->staff->update(['permissions' => ['users.view', 'users.manage']]);
    Sanctum::actingAs($this->staff);

    patchJson("/api/admin/users/{$this->staff->id}", ['permissions' => ['users.view', 'users.manage', 'quotes.edit']])
        ->assertStatus(422)->assertJsonFragment(['message' => 'You cannot change your own access.']);
});

it('stops a delegated users manager from handing out sensitive access', function (): void {
    $this->staff->update(['permissions' => ['users.view', 'users.manage']]);
    $target = User::factory()->staffAdmin()->create();
    Sanctum::actingAs($this->staff);

    patchJson("/api/admin/users/{$target->id}", ['permissions' => ['quotes.view', 'pricing.manage']])
        ->assertStatus(422)->assertJsonFragment(['message' => 'Only a superadmin can grant Pricing or Users access.']);
});

it('still lets a delegated users manager grant operational access to others', function (): void {
    $this->staff->update(['permissions' => ['users.view', 'users.manage']]);
    $target = User::factory()->staffAdmin()->create();
    Sanctum::actingAs($this->staff);

    patchJson("/api/admin/users/{$target->id}", ['permissions' => ['quotes.view', 'production.manage']])->assertOk();
    expect($target->fresh()->permissions)->toBe(['quotes.view', 'production.manage']);
});

it('lets a superadmin grant the sensitive sections', function (): void {
    Sanctum::actingAs($this->superadmin);

    patchJson("/api/admin/users/{$this->staff->id}", ['permissions' => ['pricing.view', 'users.view']])
        ->assertOk()->assertJsonPath('data.permissions', ['pricing.view', 'users.view']);
});
