<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Production job — a unit of work on the floor. Named "production_jobs" to avoid
 * the Laravel queue "jobs" table. One quote can spawn multiple jobs (UV track +
 * 3D track). ready_at drives FCFS queue order (spec principle 2: readiness, not
 * order time). The (state, ready_at) index backs the shared-queue read.
 *
 * Job state machine (spec 5.4): READY -> IN_PRODUCTION -> SHIPPED -> CLOSED.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quote_id')->constrained('quotes')->cascadeOnDelete();
            $table->enum('track', ['UV', '3D']);
            $table->timestamp('ready_at')->nullable()->comment('drives FCFS queue order');
            $table->enum('state', ['READY', 'IN_PRODUCTION', 'SHIPPED', 'CLOSED'])->default('READY');
            $table->string('artwork_ref')->nullable()->comment('print-ready file = approved proof artwork');
            $table->enum('print_method', ['UV', 'FDM', 'RESIN'])->nullable();
            $table->integer('qty')->default(0);
            $table->foreignId('created_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('quote_id');
            $table->index('track');
            $table->index('state');
            $table->index('ready_at');
            $table->index(['state', 'ready_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_jobs');
    }
};
