<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Free-form money adjustments staff apply after delivery - a discount, a tax, a
 * surcharge - each a free-text label and a signed amount (negative discounts,
 * positive charges). Folded into the order total alongside subtotal and
 * delivery. Stored as an ordered JSON list rather than a table: the set is
 * small, always read whole with its quote, and has no identity of its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->json('adjustments')->nullable()->after('delivery');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->dropColumn('adjustments');
        });
    }
};
