<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Measured print time from the slicer (minutes). When present, MODEL_3D
 * landed cost uses it directly instead of the minutes-per-gram proxy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->decimal('est_print_minutes', 8, 1)->nullable()->after('est_grams');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('est_print_minutes');
        });
    }
};
