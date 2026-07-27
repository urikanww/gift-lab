<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Idempotent: the queue tables already exist on some environments
        // (created outside migration history), so only create when absent.
        if (Schema::hasTable('jobs')) {
            return;
        }

        Schema::create('jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Deliberately a no-op. up() only creates `jobs` when it was absent (a
     * guarded no-op on environments where the queue tables already exist
     * outside migration history) - so this migration never reliably OWNS the
     * table. An unconditional dropIfExists() here would drop the
     * framework-owned queue table - every pending/in-flight job - on a
     * rollback of an environment where `jobs` pre-existed, which is exactly
     * the case up() was written to protect.
     */
    public function down(): void
    {
        //
    }
};
