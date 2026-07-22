<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buyer-supplied reference images attached to a "request changes" (optional).
 * Stored as an array of object-store keys (artwork/…) alongside the free-text
 * `notes` so staff can see WHAT the buyer wants changed, not just read it.
 * Bound to the proof the buyer sent back, so it travels with that version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proofs', function (Blueprint $table): void {
            $table->json('change_refs')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('proofs', function (Blueprint $table): void {
            $table->dropColumn('change_refs');
        });
    }
};
