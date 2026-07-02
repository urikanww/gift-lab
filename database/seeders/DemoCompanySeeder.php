<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds one demo buyer company + a seated buyer user so the B2B flow
 * (login -> catalogue -> quote -> PO) is exercisable end-to-end. Companies are
 * normally admin-provisioned (spec); this is demo/bootstrap data. Change the
 * password after first login. Run explicitly:
 *   php artisan db:seed --class=DemoCompanySeeder
 */
class DemoCompanySeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $companyId = DB::table('companies')->where('name', 'NexGen Pte Ltd')->value('id');

        if ($companyId === null) {
            $companyId = DB::table('companies')->insertGetId([
                'name' => 'NexGen Pte Ltd',
                'registration_no' => '202412345K',
                'billing_email' => 'admin@nexgen.com.sg',
                'phone' => '+65 6123 4567',
                'address' => '71 Ayer Rajah Crescent, #02-18, Singapore 139951',
                'default_terms' => 'NET 30',
                'status' => 'ACTIVE',
                'created_by' => DB::table('users')->where('role', 'superadmin')->value('id'),
                'updated_at' => $now,
                'created_at' => $now,
            ]);
        }

        DB::table('users')->updateOrInsert(
            ['email' => 'buyer@nexgen.com.sg'],
            [
                'company_id' => $companyId,
                'name' => 'NexGen Buyer',
                'email_verified_at' => $now,
                'password' => Hash::make('ChangeMe!123'),
                'role' => 'buyer',
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );
    }
}
