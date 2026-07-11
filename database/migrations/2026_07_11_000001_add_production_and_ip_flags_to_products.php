<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-file model + non-blocking IP flag (see docs/PLAN-catalogue-s3-bambu-production.md).
 *
 * - production_file_ref: the file the print floor actually prints. Nullable and
 *   FALLS BACK to model_file_ref. Thingiverse leaves it null (floor uses the STL);
 *   MakerWorld sets it to the H2S-targeted .3mf while model_file_ref stays a derived
 *   STL for the viewer/dimensions/estimate-slice.
 * - ip_flagged / ip_flag_reason: an item can be branded-but-otherwise-valid. The IP
 *   screen now surfaces this as a NON-BLOCKING tag (badge + human approval) instead of
 *   forcing CANNOT_PUBLISH. Add-only: existing rows default to not-flagged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('production_file_ref')->nullable()->after('model_file_ref');
            $table->boolean('ip_flagged')->default(false)->after('production_file_ref');
            $table->string('ip_flag_reason')->nullable()->after('ip_flagged');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['production_file_ref', 'ip_flagged', 'ip_flag_reason']);
        });
    }
};
