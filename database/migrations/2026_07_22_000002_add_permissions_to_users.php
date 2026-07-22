<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user granular access, granted by a superadmin (see App\Support\Permissions).
 *
 * NULL means "unrestricted" - a staff_admin with no explicit set keeps access to
 * everything, so existing staff are grandfathered in and nothing breaks on
 * rollout. A superadmin restricts an account by saving an explicit allowlist.
 * Only meaningful for staff_admin: superadmin is always full, buyers always none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->json('permissions')->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('permissions');
        });
    }
};
