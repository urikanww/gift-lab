<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Line item on a quote, also assigned to a production job once ready.
 * Procurement state machine (spec 5.2): a job enters the queue only when ALL
 * its line items reach READY or DROPPED; one failed line never kills the rest.
 *
 * frozen_snapshot captures price/spec at quote freeze (spec 6.4) so a scraper
 * background sync can never mutate an in-flight quote line.
 *
 * product_id uses restrictOnDelete to preserve the historical order record even
 * if a catalogue product is later removed (soft-deletes apply to products).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('line_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quote_id')->constrained('quotes')->cascadeOnDelete();
            $table->foreignId('job_id')->nullable()
                ->constrained('production_jobs')->nullOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('variant_id')->nullable()
                ->constrained('variants')->nullOnDelete();

            $table->integer('qty')->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->char('currency', 3)->default('SGD');

            $table->json('customization')->nullable()->comment('{logo_size,name_text,artwork_ref}');

            $table->enum('line_state', [
                'PENDING',
                'PROCURING',
                'PURCHASED',
                'INBOUND',
                'RECEIVED',
                'READY',
                'AWAITING_RECONFIRM',
                'AMENDED',
                'DROPPED',
                'CANCELLED',
            ])->default('PENDING');

            $table->integer('procured_qty')->nullable();
            $table->decimal('procured_price', 12, 2)->nullable();
            $table->json('frozen_snapshot')->nullable()->comment('price/spec freeze at quote time');
            $table->integer('lead_time_days')->nullable();

            $table->timestamps();

            $table->index('quote_id');
            $table->index('job_id');
            $table->index('product_id');
            $table->index('line_state');
            $table->index(['quote_id', 'line_state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('line_items');
    }
};
