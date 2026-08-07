<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Deterministic fixture for the Playwright E2E suite (frontend/e2e).
 *
 * Everything an end-to-end login -> checkout journey needs, with STABLE,
 * known-in-advance identifiers so the specs can select by them:
 *  - staff accounts (delegated to AdminUserSeeder: superadmin + ops staff_admin,
 *    password 'ChangeMe!123' in local/testing)
 *  - pricing config (checkout price-estimate + quote store need it)
 *  - a corporate buyer with a company (buyers are never seeded by the app -
 *    they are admin-provisioned - so the E2E buyer must be created here)
 *  - one PUBLISHED CORE product + a variant, so the catalogue is non-empty and
 *    the product is quotable (the E4 completeness guard needs >=1 variant)
 *
 * Credentials are mirrored in frontend/e2e/fixtures/roles.ts - change both
 * together or the login journey breaks.
 *
 * Idempotent: safe to re-run against an existing DB without duplicating rows.
 * Intended to run against an ISOLATED e2e database - see frontend/e2e/README.md.
 */
class E2eSeeder extends Seeder
{
    /** Kept in sync with frontend/e2e/fixtures/roles.ts */
    public const BUYER_EMAIL = 'buyer.e2e@giftlab.local';
    public const BUYER_PASSWORD = 'E2ePass!123';
    public const PRODUCT_NAME = 'E2E Fixture Mug';

    public function run(): void
    {
        // Staff accounts + pricing/courier config. AdminUserSeeder seeds the
        // superadmin + ops staff_admin the role matrix logs in as.
        $this->call([
            AdminUserSeeder::class,
            PricingConfigSeeder::class,
            CourierConfigSeeder::class,
        ]);

        // Corporate buyer + company. firstOrCreate keeps re-runs idempotent.
        $company = Company::query()->firstOrCreate(
            ['name' => 'E2E Fixtures Pte Ltd'],
            [
                'billing_email' => self::BUYER_EMAIL,
                'phone' => '+6560000000',
                'address' => '1 Marina Blvd, Singapore 018989',
                'status' => 'ACTIVE',
            ],
        );

        $buyer = User::query()->firstOrCreate(
            ['email' => self::BUYER_EMAIL],
            [
                'company_id' => $company->id,
                'name' => 'E2E Buyer',
                'email_verified_at' => now(),
                'password' => Hash::make(self::BUYER_PASSWORD),
                'role' => UserRole::Buyer->value,
            ],
        );

        // Close the created_by loop if this is a fresh company.
        if ($company->created_by === null) {
            $company->forceFill(['created_by' => $buyer->id])->save();
        }

        // One quotable product: PUBLISHED CORE + at least one variant (E4 guard).
        $product = Product::query()->where('name', self::PRODUCT_NAME)->first();
        if ($product === null) {
            $product = Product::factory()->create([
                'name' => self::PRODUCT_NAME,
                'class' => 'CORE',
                'publish_state' => 'PUBLISHED',
            ]);
            Variant::factory()->create(['product_id' => $product->id]);
        }
    }
}
