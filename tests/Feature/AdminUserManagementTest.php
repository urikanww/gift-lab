<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

// Superadmin-only user management: list/filter, create, edit (with self/
// last-superadmin guardrails), deactivate/reactivate, and password reset.

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->superadmin = User::factory()->create(['role' => 'superadmin', 'company_id' => null]);
    $this->staffAdmin = User::factory()->staffAdmin()->create();
    $this->buyer = User::factory()->create(['role' => 'buyer', 'company_id' => $this->company->id]);
});

it('lets the superadmin list users and blocks staff/buyer', function (): void {
    Sanctum::actingAs($this->superadmin);

    $response = $this->getJson('/api/admin/users')->assertOk();
    $response->assertJsonStructure([
        'data' => [['id', 'name', 'email', 'role', 'company', 'active', 'created_at']],
        'meta' => ['current_page', 'last_page', 'per_page', 'total'],
    ]);
    // At least the 3 users created in beforeEach.
    expect($response->json('meta.total'))->toBeGreaterThanOrEqual(3);

    Sanctum::actingAs($this->staffAdmin);
    $this->getJson('/api/admin/users')->assertForbidden();

    Sanctum::actingAs($this->buyer);
    $this->getJson('/api/admin/users')->assertForbidden();
});

it('filters users by role and by q (email)', function (): void {
    Sanctum::actingAs($this->superadmin);

    $this->getJson('/api/admin/users?role=buyer')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);

    $this->getJson('/api/admin/users?role=staff_admin')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);

    $response = $this->getJson('/api/admin/users?q='.urlencode($this->buyer->email))->assertOk();
    expect($response->json('meta.total'))->toBe(1)
        ->and($response->json('data.0.email'))->toBe($this->buyer->email);
});

it('matches q against company name', function (): void {
    Sanctum::actingAs($this->superadmin);

    $acme = Company::factory()->create(['name' => 'Zenith Widgets Pte Ltd']);
    $target = User::factory()->create(['company_id' => $acme->id, 'role' => 'buyer', 'name' => 'Unrelated Name', 'email' => 'x@other.example']);

    $response = $this->getJson('/api/admin/users?q=zenith')->assertOk();
    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($target->id);
});

it('matches q against an exact numeric id', function (): void {
    Sanctum::actingAs($this->superadmin);

    // Digit-free name/email and no company, so the ONLY way this user can match
    // its own numeric id is the id branch - not a LIKE coincidence on name/email.
    $target = User::factory()->create([
        'role' => 'staff_admin',
        'name' => 'Alpha Bravo',
        'email' => 'alpha.bravo@example.test',
    ]);

    $response = $this->getJson('/api/admin/users?q='.$target->id)->assertOk();
    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($target->id);
});

it('returns companies id/name and blocks buyer access', function (): void {
    Sanctum::actingAs($this->superadmin);

    $response = $this->getJson('/api/admin/companies')->assertOk();
    $response->assertJsonStructure(['data' => [['id', 'name']]]);

    Sanctum::actingAs($this->buyer);
    $this->getJson('/api/admin/companies')->assertForbidden();
});

it('creates a staff user with company_id forced null', function (): void {
    Sanctum::actingAs($this->superadmin);

    $response = $this->postJson('/api/admin/users', [
        'name' => 'New Staff',
        'email' => 'new.staff@example.com',
        'password' => 'password123',
        'role' => 'staff_admin',
        'company_id' => $this->company->id, // should be ignored/forced null
    ])->assertCreated();

    $response->assertJsonPath('data.role', 'staff_admin')
        ->assertJsonPath('data.company', null);

    $created = User::where('email', 'new.staff@example.com')->firstOrFail();
    expect($created->company_id)->toBeNull();
    $this->assertDatabaseHas('audit_logs', ['event' => 'user.created']);
});

it('rejects creating a buyer account (buyers self-register)', function (): void {
    Sanctum::actingAs($this->superadmin);

    $this->postJson('/api/admin/users', [
        'name' => 'Nope Buyer',
        'email' => 'nope.buyer@example.com',
        'password' => 'password123',
        'role' => 'buyer',
        'company_id' => $this->company->id,
    ])->assertStatus(422)->assertJsonValidationErrors('role');

    expect(User::where('email', 'nope.buyer@example.com')->exists())->toBeFalse();
});

it('rejects duplicate email on create', function (): void {
    Sanctum::actingAs($this->superadmin);

    $this->postJson('/api/admin/users', [
        'name' => 'Dup',
        'email' => $this->buyer->email,
        'password' => 'password123',
        'role' => 'staff_admin',
    ])->assertStatus(422)->assertJsonValidationErrors('email');
});

it('shows a user and updates their name', function (): void {
    Sanctum::actingAs($this->superadmin);

    $this->getJson("/api/admin/users/{$this->buyer->id}")
        ->assertOk()
        ->assertJsonPath('data.email', $this->buyer->email);

    $this->patchJson("/api/admin/users/{$this->buyer->id}", ['name' => 'Renamed Buyer'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Renamed Buyer');

    $this->assertDatabaseHas('audit_logs', ['event' => 'user.updated']);
});

it('forbids changing your own role', function (): void {
    Sanctum::actingAs($this->superadmin);

    $this->patchJson("/api/admin/users/{$this->superadmin->id}", ['role' => 'staff_admin'])
        ->assertStatus(422);
});

it('rejects changing a user to the buyer role', function (): void {
    Sanctum::actingAs($this->superadmin);
    $staff = User::factory()->staffAdmin()->create();

    $this->patchJson("/api/admin/users/{$staff->id}", [
        'role' => 'buyer',
        'company_id' => $this->company->id,
    ])->assertStatus(422)->assertJsonValidationErrors('role');

    expect($staff->fresh()->role->value)->toBe('staff_admin');
});

it('protects the last active superadmin from demotion but allows demoting a spare one', function (): void {
    $secondSuperadmin = User::factory()->create(['role' => 'superadmin', 'company_id' => null]);

    // Acting as the second superadmin, demote the first - fine because two exist.
    Sanctum::actingAs($secondSuperadmin);
    $this->patchJson("/api/admin/users/{$this->superadmin->id}", ['role' => 'staff_admin'])
        ->assertOk()
        ->assertJsonPath('data.role', 'staff_admin');

    // Now only $secondSuperadmin is left as superadmin. Acting as itself and
    // trying to demote itself hits the last-superadmin guard (also self-role
    // guard, but last-superadmin is the more specific business rule here).
    $this->patchJson("/api/admin/users/{$secondSuperadmin->id}", ['role' => 'staff_admin'])
        ->assertStatus(422);
});

it('deactivates a user (soft delete, active:false) and reactivates them', function (): void {
    Sanctum::actingAs($this->superadmin);

    $response = $this->deleteJson("/api/admin/users/{$this->buyer->id}")->assertOk();
    $response->assertJsonPath('data.active', false);

    // Deactivated user is blocked: the soft-delete default query scope on
    // User::find() excludes trashed rows, which is what would back a real
    // Sanctum EloquentUserProvider re-fetch on the next request. We assert
    // that directly rather than via Sanctum::actingAs(), since actingAs()
    // injects the user object straight into the guard and bypasses any
    // provider re-fetch/scope check entirely (it would not actually catch a
    // soft-deleted user, so it isn't a meaningful assertion of "blocked").
    expect(User::find($this->buyer->id))->toBeNull();
    expect(User::withTrashed()->find($this->buyer->id)->trashed())->toBeTrue();

    $this->assertDatabaseHas('audit_logs', ['event' => 'user.deactivated']);

    $reactivateResponse = $this->postJson("/api/admin/users/{$this->buyer->id}/reactivate")->assertOk();
    $reactivateResponse->assertJsonPath('data.active', true);

    expect(User::find($this->buyer->id))->not->toBeNull();
    $this->assertDatabaseHas('audit_logs', ['event' => 'user.reactivated']);
});

it('forbids deactivating yourself and the last active superadmin', function (): void {
    Sanctum::actingAs($this->superadmin);

    $this->deleteJson("/api/admin/users/{$this->superadmin->id}")->assertStatus(422);

    $secondSuperadmin = User::factory()->create(['role' => 'superadmin', 'company_id' => null]);
    Sanctum::actingAs($secondSuperadmin);

    // Deactivating the other (non-self) superadmin is fine - two exist.
    $this->deleteJson("/api/admin/users/{$this->superadmin->id}")->assertOk();

    // Now $secondSuperadmin is the last one; deactivating self also triggers
    // the self-guard, but exercise the last-superadmin path by having a
    // different actor attempt it isn't possible without self here - assert
    // 422 either way since both guards fire.
    $this->deleteJson("/api/admin/users/{$secondSuperadmin->id}")->assertStatus(422);
});

it('resets a password and the new hash verifies', function (): void {
    Sanctum::actingAs($this->superadmin);

    $this->postJson("/api/admin/users/{$this->buyer->id}/password", [
        'password' => 'brand-new-pass1',
    ])->assertOk();

    $updated = User::find($this->buyer->id);
    expect(Hash::check('brand-new-pass1', $updated->password))->toBeTrue();

    $this->assertDatabaseHas('audit_logs', ['event' => 'user.password_reset']);
});

it('blocks staff and buyer from store/update/deactivate', function (): void {
    Sanctum::actingAs($this->staffAdmin);
    $this->postJson('/api/admin/users', [
        'name' => 'X', 'email' => 'x@example.com', 'password' => 'password123', 'role' => 'staff_admin',
    ])->assertForbidden();
    $this->patchJson("/api/admin/users/{$this->buyer->id}", ['name' => 'X'])->assertForbidden();
    $this->deleteJson("/api/admin/users/{$this->buyer->id}")->assertForbidden();

    Sanctum::actingAs($this->buyer);
    $this->postJson('/api/admin/users', [
        'name' => 'X', 'email' => 'x2@example.com', 'password' => 'password123', 'role' => 'staff_admin',
    ])->assertForbidden();
    $this->patchJson("/api/admin/users/{$this->staffAdmin->id}", ['name' => 'X'])->assertForbidden();
    $this->deleteJson("/api/admin/users/{$this->staffAdmin->id}")->assertForbidden();
});

it('forbids a delegated user-manager from resetting a superadmin password (privilege escalation)', function (): void {
    // A staff_admin explicitly granted users.manage (so the route middleware
    // passes) must still not be able to hijack a superadmin account.
    $manager = User::factory()->create([
        'role' => 'staff_admin',
        'company_id' => null,
        'permissions' => ['users.view', 'users.manage'],
    ]);
    $target = User::factory()->create(['role' => 'superadmin', 'company_id' => null]);

    Sanctum::actingAs($manager);

    $this->postJson("/api/admin/users/{$target->id}/password", [
        'password' => 'hijacked-pass1',
    ])->assertForbidden();

    expect(Hash::check('hijacked-pass1', User::find($target->id)->password))->toBeFalse();
});

it('still lets a delegated user-manager reset a non-superadmin password', function (): void {
    $manager = User::factory()->create([
        'role' => 'staff_admin',
        'company_id' => null,
        'permissions' => ['users.view', 'users.manage'],
    ]);

    Sanctum::actingAs($manager);

    $this->postJson("/api/admin/users/{$this->buyer->id}/password", [
        'password' => 'new-buyer-pass1',
    ])->assertOk();

    expect(Hash::check('new-buyer-pass1', User::find($this->buyer->id)->password))->toBeTrue();
});
