<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Support\Facades\Hash;

/**
 * AdminUserSeeder must never let the old repo-public 'ChangeMe!123' password
 * reach a real (non local/testing) environment. See ADMIN_SEED_PASSWORD in
 * .env.example.
 */
function setAdminSeedPasswordEnv(?string $value): void
{
    if ($value === null) {
        putenv('ADMIN_SEED_PASSWORD');
        unset($_ENV['ADMIN_SEED_PASSWORD'], $_SERVER['ADMIN_SEED_PASSWORD']);

        return;
    }

    putenv("ADMIN_SEED_PASSWORD={$value}");
    $_ENV['ADMIN_SEED_PASSWORD'] = $value;
    $_SERVER['ADMIN_SEED_PASSWORD'] = $value;
}

beforeEach(fn () => setAdminSeedPasswordEnv(null));

afterEach(function (): void {
    setAdminSeedPasswordEnv(null);
    // Restore the "testing" env for every other test in the suite.
    app()->instance('env', 'testing');
});

it('seeds the superadmin and staff_admin accounts in testing with no env var set', function (): void {
    (new AdminUserSeeder)->run();

    $superadmin = User::where('email', 'superadmin@giftlab.local')->first();
    $ops = User::where('email', 'ops@giftlab.local')->first();

    expect($superadmin)->not->toBeNull()
        ->and($superadmin->role->value)->toBe('superadmin')
        ->and($superadmin->company_id)->toBeNull()
        ->and($ops)->not->toBeNull()
        ->and($ops->role->value)->toBe('staff_admin');

    // Dev-only fallback password, so local dev + seeder-dependent flows work.
    expect(Hash::check('ChangeMe!123', $superadmin->password))->toBeTrue()
        ->and(Hash::check('ChangeMe!123', $ops->password))->toBeTrue();
});

it('never creates the seeded admins with the hardcoded default password outside local/testing', function (): void {
    app()->instance('env', 'production');

    (new AdminUserSeeder)->run();

    $superadmin = User::where('email', 'superadmin@giftlab.local')->first();
    $ops = User::where('email', 'ops@giftlab.local')->first();

    // Accounts still get created (randomized), but the repo-public password
    // must never work.
    expect($superadmin)->not->toBeNull()
        ->and($ops)->not->toBeNull()
        ->and(Hash::check('ChangeMe!123', $superadmin->password))->toBeFalse()
        ->and(Hash::check('ChangeMe!123', $ops->password))->toBeFalse();
});

it('uses ADMIN_SEED_PASSWORD when explicitly provided, in any environment', function (): void {
    app()->instance('env', 'production');
    setAdminSeedPasswordEnv('S3cure-Ops-Pass!42');

    (new AdminUserSeeder)->run();

    $superadmin = User::where('email', 'superadmin@giftlab.local')->first();
    $ops = User::where('email', 'ops@giftlab.local')->first();

    expect(Hash::check('S3cure-Ops-Pass!42', $superadmin->password))->toBeTrue()
        ->and(Hash::check('S3cure-Ops-Pass!42', $ops->password))->toBeTrue();
});

it('is idempotent and never overwrites an existing account password on re-run', function (): void {
    (new AdminUserSeeder)->run();

    $superadmin = User::where('email', 'superadmin@giftlab.local')->first();

    // Simulate an operator having rotated the password in production.
    $superadmin->forceFill(['password' => Hash::make('RotatedProdPass!99')])->save();

    // Re-running the seeder (e.g. via db:seed on redeploy) with no env var
    // set must NOT reset the password back to the dev default.
    (new AdminUserSeeder)->run();

    expect(User::where('email', 'superadmin@giftlab.local')->count())->toBe(1);

    $superadmin->refresh();
    expect(Hash::check('RotatedProdPass!99', $superadmin->password))->toBeTrue()
        ->and(Hash::check('ChangeMe!123', $superadmin->password))->toBeFalse();
});

it('applies ADMIN_SEED_PASSWORD to an existing account on an explicit re-run', function (): void {
    (new AdminUserSeeder)->run();

    setAdminSeedPasswordEnv('ForcedReset!123');
    (new AdminUserSeeder)->run();

    $superadmin = User::where('email', 'superadmin@giftlab.local')->first();
    expect(Hash::check('ForcedReset!123', $superadmin->password))->toBeTrue();
});
