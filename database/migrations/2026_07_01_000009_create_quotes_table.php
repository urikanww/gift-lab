<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quote - the order spine header. State machine (spec 5.1):
 * DRAFT -> SENT -> (CHANGES_REQUESTED -> DRAFT)* -> ACCEPTED -> PROOFING
 *   -> PROOF_APPROVED -> PO_ISSUED -> CONFIRMED -> PROCURING -> READY -> CLOSED
 * CONFIRMED/PROCURING -> CANCELLED allowed.
 *
 * price_snapshot_at freezes pricing at send time. amendment_log records every
 * admin field change (who/what/when) for B2B dispute protection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->enum('state', [
                'DRAFT',
                'SENT',
                'CHANGES_REQUESTED',
                'ACCEPTED',
                'PROOFING',
                'PROOF_APPROVED',
                'PO_ISSUED',
                'CONFIRMED',
                'PROCURING',
                'READY',
                'CLOSED',
                'CANCELLED',
            ])->default('DRAFT');

            $table->char('currency', 3)->default('SGD');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('delivery', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);

            $table->timestamp('price_snapshot_at')->nullable();
            $table->json('amendment_log')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignId('amended_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('company_id');
            $table->index('state');
            $table->index(['company_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotes');
    }
};
