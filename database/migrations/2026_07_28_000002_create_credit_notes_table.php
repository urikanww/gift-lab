<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B2B has no payment gateway to refund through, so cancelling a PAID/PARTIAL
 * invoice closes the money loop with a credit-note record instead of an
 * actual transfer - see QuoteService::voidInvoiceAndCredit(). amount is a
 * verbatim (GST-inclusive) copy of the invoice amount it was minted against,
 * never recomputed - same money shape as every other SGD column in the app
 * (decimal(12,2)).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quote_id')->constrained('quotes')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('reason')->nullable();
            $table->foreignId('issued_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('quote_id');
            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_notes');
    }
};
