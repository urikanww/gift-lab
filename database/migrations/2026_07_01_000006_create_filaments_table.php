<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 3D filament inventory (Phase 2 track). MODEL_3D jobs consume filament rather
 * than a sourced blank. qty tracked in grams (decimal for partial spools).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('filaments', function (Blueprint $table): void {
            $table->id();
            $table->string('material')->comment('e.g. PLA, PETG, ABS, RESIN');
            $table->string('color');
            $table->decimal('qty_on_hand', 12, 3)->default(0)->comment('grams');
            $table->decimal('reorder_threshold', 12, 3)->default(0)->comment('grams');
            $table->timestamps();

            $table->unique(['material', 'color']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('filaments');
    }
};
