<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PDPA provenance marker: records HOW each user was created, so a null
 * consented_at is unambiguous. Defaults to 'legacy' so pre-existing rows
 * backfill to the re-consent-target state; the register and admin-create paths
 * set 'self_registered' / 'staff_created' explicitly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('registration_source', 20)->default('legacy')->after('consent_policy_version');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('registration_source');
        });
    }
};
