<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase order + invoice for a confirmed quote. B2B launch has no Stripe:
 * payment_state is reconciled manually by staff; PO/invoice are documents
 * (PDF generated downstream) plus the terms + payment audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quote_id')->constrained('quotes')->cascadeOnDelete();
            $table->string('po_ref')->unique();
            $table->string('invoice_ref')->nullable()->unique();
            $table->string('terms')->nullable();
            $table->enum('payment_state', ['UNPAID', 'PARTIAL', 'PAID', 'VOID'])->default('UNPAID');
            $table->decimal('amount', 12, 2)->default(0);
            $table->char('currency', 3)->default('SGD');
            $table->foreignId('issued_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();

            $table->index('quote_id');
            $table->index('payment_state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
