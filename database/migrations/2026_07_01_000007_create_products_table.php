<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogue product. class discriminates the three procurement tracks that
 * share the order spine (CORE spine ships first; SCRAPED_UV + MODEL_3D columns
 * are present now so Phase 2 needs no re-migration).
 *
 * Freeze-on-quote (spec 6.4) is handled per line item (line_items.frozen_snapshot),
 * not as a product state, so publish_state stays a pure catalogue lifecycle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('class', ['CORE', 'SCRAPED_UV', 'MODEL_3D'])->default('CORE');

            // Money: SGD single-currency v1, schema multi-currency-ready.
            $table->decimal('base_cost', 12, 2)->default(0);
            $table->char('currency', 3)->default('SGD');

            $table->json('dimensions')->nullable()->comment('{l,w,h,unit}');
            $table->decimal('weight', 12, 3)->nullable()->comment('grams');
            $table->enum('print_method', ['UV', 'FDM', 'RESIN'])->nullable();

            $table->enum('publish_state', [
                'PENDING',
                'READY_TO_APPROVE',
                'PUBLISHED',
                'CANNOT_PUBLISH',
            ])->default('PENDING');
            $table->json('cannot_publish_reasons')->nullable()
                ->comment('missing_price|missing_dimensions|not_printable|stock_unreadable|source_dead');

            $table->enum('stock_mode', ['STOCKED', 'MAKE_TO_ORDER'])->default('STOCKED');

            $table->string('image_url')->nullable();
            $table->string('source_url')->nullable()->comment('scraped origin');
            $table->string('source_product_id')->nullable()->comment('scraped origin id');
            $table->integer('stock_estimate')->nullable()->comment('scraped/indicative, non-authoritative');
            $table->boolean('is_printable')->default(false);

            // MODEL_3D linkage + spec-listed licence fields on the product.
            $table->foreignId('model3d_id')->nullable()
                ->constrained('model3ds')->nullOnDelete();
            $table->enum('license', ['CC0', 'CC_BY', 'OWNED', 'BLOCKED'])->nullable();
            $table->string('creator_credit')->nullable();
            $table->string('model_file_ref')->nullable();

            $table->foreignId('created_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('class');
            $table->index('publish_state');
            $table->index('stock_mode');
            $table->index(['class', 'publish_state']);
            $table->index('source_product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
