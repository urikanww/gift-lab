<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Variants of CORE products (colour/size/material trees). stock_on_hand is the
 * authoritative on-floor count for the "blank is on the floor" production gate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->json('attributes')->comment('{color,size,material}');
            $table->string('sku')->nullable()->unique();
            $table->integer('stock_on_hand')->default(0);
            $table->integer('reorder_threshold')->default(0);
            $table->decimal('price_delta', 12, 2)->default(0);
            $table->char('currency', 3)->default('SGD');
            $table->timestamps();

            $table->index('product_id');
            $table->index('stock_on_hand');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variants');
    }
};
