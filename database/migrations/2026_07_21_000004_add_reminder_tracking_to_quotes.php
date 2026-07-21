<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks how often a buyer has been chased, so reminders do not repeat daily.
 *
 * Nothing chased anything before this: a SENT quote or an unanswered proof sat
 * forever with no nudge to either side, and staff carried it by memory.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->unsignedTinyInteger('reminders_sent')->default(0)->after('stock_confirmed_by');
            $table->timestamp('last_reminded_at')->nullable()->after('reminders_sent');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->dropColumn(['reminders_sent', 'last_reminded_at']);
        });
    }
};
