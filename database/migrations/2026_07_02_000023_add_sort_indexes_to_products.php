<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Composite indexes matching the public catalogue's query shape
 * (publish_state filter + sort column) so sort=price_asc, price_desc and
 * newest never filesort on this unauthenticated, scrapeable endpoint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->index(['publish_state', 'base_cost']);
            $table->index(['publish_state', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['publish_state', 'base_cost']);
            $table->dropIndex(['publish_state', 'created_at']);
        });
    }
};
