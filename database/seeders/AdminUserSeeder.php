<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds the internal staff accounts (company_id null). Buyers are never seeded
 * here - they are admin-provisioned per company. Passwords are bcrypt-hashed;
 * change immediately after first deploy.
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

        foreach ($staff as [$name, $email, $role]) {
            DB::table('users')->updateOrInsert(
                ['email' => $email],
                [
                    'company_id' => null,
                    'name' => $name,
                    'email_verified_at' => $now,
                    'password' => Hash::make('ChangeMe!123'),
                    'role' => $role,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }
    }
}
