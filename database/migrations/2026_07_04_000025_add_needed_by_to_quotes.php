<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buyer's "need it by" deadline, captured in the designer/checkout. Nullable —
 * a date is optional; the shipping address deliberately reuses the company's
 * stored address, so only the deadline is persisted per quote.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->date('needed_by')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->dropColumn('needed_by');
        });
    }
};
