<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "On-demand" ordering: when true, a STOCKED (UV/CORE) product can be ordered at
 * stock 0. The short quantity drives on-hand negative - that negative balance is
 * the procurement worklist (buy the blank from the affiliate source). 3D items
 * are MAKE_TO_ORDER and ignore this flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('allow_backorder')->default(false)->after('stock_mode');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('allow_backorder');
        });
    }
};
