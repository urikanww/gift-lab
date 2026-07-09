<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff-gated public 3D preview. Source models are uncurated (wrong/partial/
 * variant geometry is common), and a raw grey STL undersells the marketing
 * thumbnail - so the interactive viewer only shows on the public PDP once staff
 * confirm the model is correct + complete. Default false: thumbnail leads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('model_preview_verified')->default(false)->after('is_printable');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('model_preview_verified');
        });
    }
};
