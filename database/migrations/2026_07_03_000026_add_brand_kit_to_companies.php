<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-company brand kit: a saved logo + brand colours the buyer applies across
 * products in one click. The logo is stored as a data URL so the designer can
 * reload it into the Fabric canvas with no CORS dependency (mirrors the
 * existing file→dataURL→canvas upload path; the object store has no CORS
 * policy). Small, single logo per company - acceptable inline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->longText('brand_logo')->nullable()->after('default_terms');
            $table->json('brand_colors')->nullable()->after('brand_logo');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn(['brand_logo', 'brand_colors']);
        });
    }
};
