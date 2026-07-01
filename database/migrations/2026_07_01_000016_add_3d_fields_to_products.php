<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MODEL_3D products consume filament rather than a sourced blank. These explicit
 * fields link a product to the filament it prints from and the estimated grams
 * per unit, so procurement can decrement the right Filament row deterministically
 * (spec Phase 2, 3D track). Nullable — irrelevant to CORE / SCRAPED_UV rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('filament_material')->nullable()->after('model_file_ref');
            $table->string('filament_color')->nullable()->after('filament_material');
            $table->decimal('est_grams', 12, 3)->nullable()->after('filament_color')
                ->comment('estimated filament grams per unit (MODEL_3D)');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['filament_material', 'filament_color', 'est_grams']);
        });
    }
};
