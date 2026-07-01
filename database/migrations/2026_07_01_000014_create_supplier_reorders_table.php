<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier reorder draft/approval. Covers CORE variant bulk reorders (below
 * threshold) and 3D filament reorders. No marketplace checkout automation
 * (spec 7): this is a human/admin/contracted-supplier purchase record.
 * State: DRAFT -> APPROVED -> ORDERED -> RECEIVED.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_reorders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('variant_id')->nullable()
                ->constrained('variants')->nullOnDelete();
            $table->foreignId('filament_id')->nullable()
                ->constrained('filaments')->nullOnDelete();
            $table->string('sku')->nullable();
            $table->decimal('qty', 12, 3)->default(0);
            $table->enum('state', ['DRAFT', 'APPROVED', 'ORDERED', 'RECEIVED'])->default('DRAFT');
            $table->foreignId('approved_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('state');
            $table->index('variant_id');
            $table->index('filament_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_reorders');
    }
};
