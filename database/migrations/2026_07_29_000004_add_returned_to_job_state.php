<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M15 adds JobState::Returned. production_jobs.state was created as an enum,
 * which bakes a CHECK constraint (SQLite) / native ENUM (MySQL) that rejects
 * the new value. Rather than fight per-driver enum ALTERs, relax the column to
 * a plain string: the value set is already enforced at the app layer by the
 * JobState enum cast and ProductionJob::transitionTo()'s guard, so the DB-level
 * whitelist was redundant defence, not the source of truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL keeps a native ENUM (fast, self-documenting); widen it in place.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE production_jobs MODIFY state ENUM('READY','IN_PRODUCTION','SHIPPED','CLOSED','RETURNED') NOT NULL DEFAULT 'READY'");

            return;
        }

        // SQLite (tests) / others: drop the baked CHECK by relaxing to string.
        Schema::table('production_jobs', function (Blueprint $table): void {
            $table->string('state', 20)->default('READY')->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE production_jobs MODIFY state ENUM('READY','IN_PRODUCTION','SHIPPED','CLOSED') NOT NULL DEFAULT 'READY'");

            return;
        }

        Schema::table('production_jobs', function (Blueprint $table): void {
            $table->string('state', 20)->default('READY')->change();
        });
    }
};
