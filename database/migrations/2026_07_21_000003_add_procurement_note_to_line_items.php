<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carries an advisory finding from procurement on the line it concerns.
 *
 * A quantity shortfall no longer blocks the order: it is measured against stock
 * figures nobody maintains, so blocking on them means orders held up by
 * shortages that do not exist. The finding is still worth showing - staff check
 * it at the production gate - so it is recorded rather than discarded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('line_items', function (Blueprint $table): void {
            $table->string('procurement_note', 500)->nullable()->after('procured_price');
        });
    }

    public function down(): void
    {
        Schema::table('line_items', function (Blueprint $table): void {
            $table->dropColumn('procurement_note');
        });
    }
};
