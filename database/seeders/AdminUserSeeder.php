<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Seeds the internal staff accounts (company_id null). Buyers are never seeded
 * here - they are admin-provisioned per company.
 *
 * Password source, in order:
 *  1. ADMIN_SEED_PASSWORD env var, if set - used verbatim (any environment).
 *  2. local/testing with no env var - falls back to a fixed dev password
 *     ('ChangeMe!123') so local dev and seeder-dependent tests keep working.
 *  3. Any other environment with no env var - refuses to use a repo-known
 *     password. Generates a random one instead and prints it ONCE to the
 *     console; the plaintext is never persisted anywhere.
 *
 * Idempotent (updateOrInsert) but never clobbers an existing row's password
 * on re-run - a rotated prod password must not be silently overwritten. The
 * password column is only set when the row is being created, or when
 * ADMIN_SEED_PASSWORD is explicitly provided (so an operator can force a
 * reset by re-running with the env var set).
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $staff = [
            ['Super Admin', 'superadmin@giftlab.local', 'superadmin'],
            ['Ops Admin', 'ops@giftlab.local', 'staff_admin'],
        ];

        $envPassword = env('ADMIN_SEED_PASSWORD');
        $isDevLike = app()->environment(['local', 'testing']);

        if (! $envPassword && ! $isDevLike) {
            $this->command?->getOutput()->writeln(
                '<comment>[AdminUserSeeder] ADMIN_SEED_PASSWORD is not set outside local/testing. '
                .'Refusing to use the old hardcoded default - generating a random password per '
                .'account instead. It is printed once below and NOT persisted anywhere; save it now.</comment>'
            );
        }

        foreach ($staff as [$name, $email, $role]) {
            $existingId = DB::table('users')->where('email', $email)->value('id');

            $attributes = [
                'company_id' => null,
                'name' => $name,
                'email_verified_at' => $now,
                'role' => $role,
                'updated_at' => $now,
                'created_at' => $now,
            ];

            // Only ever set a password when creating the row, or when the
            // operator explicitly supplied one via env - never clobber an
            // existing (possibly rotated) password on a routine re-seed.
            if ($existingId === null || $envPassword) {
                if ($envPassword) {
                    $plaintext = $envPassword;
                } elseif ($isDevLike) {
                    $plaintext = 'ChangeMe!123';
                } else {
                    $plaintext = Str::password(24);
                    $this->command?->getOutput()->writeln(
                        "<comment>[AdminUserSeeder] Generated password for {$email}: {$plaintext}</comment>"
                    );
                }

                $attributes['password'] = Hash::make($plaintext);
            }

            DB::table('users')->updateOrInsert(['email' => $email], $attributes);
        }
    }
}
