<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff-curated affiliate products featured on the public /gift-ideas page.
 * offer_link is the affiliate (commission) link shown publicly; product_link is
 * the plain listing (never shown to the public, kept for reference). ip_flagged
 * rows are stored but excluded from the public endpoint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gift_idea_features', function (Blueprint $table): void {
            $table->id();
            $table->string('source_product_id')->unique();
            $table->string('name');
            $table->string('image_url')->nullable();
            $table->string('offer_link');
            $table->string('product_link');
            $table->decimal('price', 12, 2)->nullable();
            $table->char('currency', 3)->default('SGD');
            $table->string('shop_name')->nullable();
            $table->boolean('ip_flagged')->default(false);
            $table->integer('sort')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['ip_flagged', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_idea_features');
    }
};
