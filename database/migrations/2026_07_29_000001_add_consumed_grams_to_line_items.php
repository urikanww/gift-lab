<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records the filament a MODEL_3D line actually consumed at procurement time.
 *
 * Filament is decremented by a direct column write with no ledger (unlike CORE
 * variant stock, which goes through StockMovement), so there was no record of
 * how much a line drew - meaning a cancelled 3D order could never return its
 * filament. Storing the grams here lets the cancel path return exactly what was
 * consumed, and only for lines that actually consumed (null = never drew, e.g.
 * a qty-short line that was accepted advisory-only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('line_items', function (Blueprint $table): void {
            $table->decimal('consumed_grams', 12, 3)->nullable()->after('procured_price');
        });
    }

    public function down(): void
    {
        Schema::table('line_items', function (Blueprint $table): void {
            $table->dropColumn('consumed_grams');
        });
    }
};
