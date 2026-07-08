<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Minimum order quantity per product. Superadmin-set (mirrors price_override):
 * a buyer cannot order fewer than this many units. Default 1 preserves existing
 * behaviour for every current product.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->unsignedInteger('min_order_qty')->default(1)->after('price_override');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('min_order_qty');
        });
    }
};
