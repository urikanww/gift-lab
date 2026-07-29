<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which chase ladder the quote's reminders_sent counter currently belongs to
 * ('price' or 'proof'). The chaser runs two independent ladders (price wait vs
 * proof wait); without recording the phase, a single reminders_sent counter is
 * shared across both, so a proof chase inherits the price-chase count (M16).
 * When the phase changes the chaser resets the counter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->string('reminded_phase', 16)->nullable()->after('reminders_sent');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->dropColumn('reminded_phase');
        });
    }
};
