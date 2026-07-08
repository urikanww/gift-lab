<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Superadmin price override: a fixed per-unit sell price that replaces the
 * dynamic PricingService computation (landed + margin + print + bulk). Null =
 * dynamic pricing (the default). Covers the product price only - delivery is
 * still charged on shipment weight.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->decimal('price_override', 10, 2)->nullable()->after('base_cost');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('price_override');
        });
    }
};
