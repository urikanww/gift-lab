<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Licence policy is now app-level (App\Enums\License), not a fixed DB enum, so
 * the commercial-OK set can grow (CC-BY-SA, GPL/LGPL, BSD/MIT/Apache) without a
 * schema change each time. Convert the rigid enum columns to plain strings;
 * validity is enforced by the enum cast + the ingest gate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('license', 30)->nullable()->change();
        });

        Schema::table('model3ds', function (Blueprint $table): void {
            $table->string('license', 30)->default('BLOCKED')->change();
        });
    }

    public function down(): void
    {
        // Best-effort revert. Rows carrying a newly-allowed licence would fall
        // outside the old enum set - collapse those to BLOCKED first so the
        // narrowed column accepts every remaining value.
        foreach (['products', 'model3ds'] as $table) {
            \Illuminate\Support\Facades\DB::table($table)
                ->whereNotIn('license', ['CC0', 'CC_BY', 'OWNED', 'BLOCKED'])
                ->update(['license' => 'BLOCKED']);
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->enum('license', ['CC0', 'CC_BY', 'OWNED', 'BLOCKED'])->nullable()->change();
        });

        Schema::table('model3ds', function (Blueprint $table): void {
            $table->enum('license', ['CC0', 'CC_BY', 'OWNED', 'BLOCKED'])->default('BLOCKED')->change();
        });
    }
};
