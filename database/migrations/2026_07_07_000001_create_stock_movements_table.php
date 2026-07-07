<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only stock ledger. Every change to a variant's on-hand count is a row
 * here; variants.stock_on_hand is a cached SUM(delta) that can be rebuilt from
 * this table. Rows are never updated or deleted — a mistake is corrected by a
 * compensating movement, so the history stays honest and reconcilable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('variant_id')->constrained('variants')->cascadeOnDelete();
            $table->integer('delta')->comment('+in / -out; on-hand is the running sum');
            $table->string('unit', 8)->default('PCS')->comment('PCS now; G/ML when 3D material lands');
            $table->string('reason', 16)->comment('INIT|RESTOCK|SALE|RETURN|ADJUST|SCRAP');
            // Polymorphic origin (order, adjustment, …) kept as a loose pair so a
            // movement can point at any source without a hard FK constraint.
            $table->string('ref_type')->nullable();
            $table->unsignedBigInteger('ref_id')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            // Append-only: created_at only, no updated_at (rows never change).
            $table->timestamp('created_at')->useCurrent();

            $table->index(['variant_id', 'created_at']);
            $table->index(['ref_type', 'ref_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
