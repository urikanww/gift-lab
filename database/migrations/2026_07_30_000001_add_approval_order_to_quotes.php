<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-order approval ordering (Feature A). Additive with a default, so every
 * existing quote reads 'price_first' and behaves exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->string('approval_order', 16)
                ->default('price_first')
                ->after('reminded_phase');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->dropColumn('approval_order');
        });
    }
};
