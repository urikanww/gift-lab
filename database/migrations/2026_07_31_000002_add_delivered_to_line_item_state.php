<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LT15: a delivered order used to still show "Ready" line items — the line
 * lifecycle had no terminal beyond READY. Add DELIVERED so a closed order's
 * READY lines advance to it (QueueService cascades on the order close).
 */
return new class extends Migration
{
    private const STATES = [
        'PENDING',
        'PROCURING',
        'PURCHASED',
        'INBOUND',
        'RECEIVED',
        'READY',
        'AWAITING_RECONFIRM',
        'AMENDED',
        'DROPPED',
        'CANCELLED',
        'DELIVERED',
    ];

    public function up(): void
    {
        Schema::table('line_items', function (Blueprint $table): void {
            $table->enum('line_state', self::STATES)->default('PENDING')->change();
        });
    }

    public function down(): void
    {
        // Reverse only when no row uses the new value, or the enum change fails.
        \Illuminate\Support\Facades\DB::table('line_items')
            ->where('line_state', 'DELIVERED')
            ->update(['line_state' => 'READY']);

        Schema::table('line_items', function (Blueprint $table): void {
            $table->enum('line_state', array_values(array_diff(self::STATES, ['DELIVERED'])))
                ->default('PENDING')
                ->change();
        });
    }
};
