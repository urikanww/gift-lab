<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The upstream "modified/updated" marker for a source model, so the daily resync
 * can re-download geometry (and re-record parts) ONLY when the creator changed
 * the model - a targeted refresh that avoids re-fetching every file every night.
 * Null for pre-existing rows (treated as "unknown" - no version-based refresh
 * until the next fetch records one).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('model3ds', function (Blueprint $table): void {
            $table->string('source_version')->nullable()->after('file_ref');
        });
    }

    public function down(): void
    {
        Schema::table('model3ds', function (Blueprint $table): void {
            $table->dropColumn('source_version');
        });
    }
};
