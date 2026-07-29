<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * Defence-in-depth for staff-only admin actions (L25, L26, L27): the gate is
 * enforced at the route/controller boundary, not left to a single in-body check.
 */

it('blocks a products.edit staff_admin (no approve) from creating a PUBLISHED product (L25)', function (): void {
    $staff = User::factory()->staffAdmin()->create(['permissions' => ['products.view', 'products.edit']]);
    Sanctum::actingAs($staff);

    $payload = [
        'name' => 'Sneaky Blank', 'base_cost' => 5.00,
        'dimensions' => ['l' => 10, 'w' => 10, 'h' => 10], 'weight' => 100,
        'print_method' => 'UV', 'stock_mode' => 'MAKE_TO_ORDER',
        'publish_state' => 'PUBLISHED',
    ];

    $this->postJson('/api/admin/products', $payload)->assertStatus(403);

    // The same staff CAN create it unpublished (PENDING).
    $this->postJson('/api/admin/products', [...$payload, 'publish_state' => 'PENDING'])->assertCreated();
});

it('lets an approver create a PUBLISHED product (L25)', function (): void {
    $staff = User::factory()->staffAdmin()->create([
        'permissions' => ['products.view', 'products.edit', 'products.approve'],
    ]);
    Sanctum::actingAs($staff);

    $this->postJson('/api/admin/products', [
        'name' => 'Approved Blank', 'base_cost' => 5.00,
        'dimensions' => ['l' => 10, 'w' => 10, 'h' => 10], 'weight' => 100,
        'print_method' => 'UV', 'stock_mode' => 'MAKE_TO_ORDER',
        'publish_state' => 'PUBLISHED',
    ])->assertCreated();
});

it('restricts the auto-publish toggle to superadmin at the route (L27)', function (): void {
    Sanctum::actingAs(User::factory()->staffAdmin()->create());
    $this->patchJson('/api/admin/settings/auto-publish', ['enabled' => true])->assertStatus(403);

    Sanctum::actingAs(User::factory()->superadmin()->create());
    $this->patchJson('/api/admin/settings/auto-publish', ['enabled' => true])->assertOk();
});

it('restricts CSV import to superadmin at the route (L26)', function (): void {
    Sanctum::actingAs(User::factory()->staffAdmin()->create());
    // A staff_admin is stopped at the route now, not confusingly deeper in.
    $this->postJson('/api/admin/products/import', ['rows' => []])->assertStatus(403);
});
