<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Decoration geometry for MODEL_3D products. `print_zone` is the single source
 * of truth for both the customer decal preview and the production print file:
 * a model-space normal + center + size (mm) locating the printable surface.
 * `decor_glb_ref` is an optional authored GLB for material realism; when absent
 * the viewer decorates the canonical STL directly. Both nullable - the STL
 * `model_file_ref` remains the slicer source.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('decor_glb_ref')->nullable()->after('model_file_ref');
            $table->json('print_zone')->nullable()->after('decor_glb_ref');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['decor_glb_ref', 'print_zone']);
        });
    }
};
