<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed the ledger from existing variants: one INIT movement per variant equal to
 * its current stock_on_hand, so the invariant "on_hand == SUM(delta)" holds from
 * day one. The cached column is left untouched (it already equals the sum).
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('variants')->select('id', 'stock_on_hand')->orderBy('id')
            ->chunk(500, function ($variants) use ($now): void {
                $rows = [];
                foreach ($variants as $variant) {
                    $rows[] = [
                        'variant_id' => $variant->id,
                        'delta' => $variant->stock_on_hand,
                        'unit' => 'PCS',
                        'reason' => 'INIT',
                        'ref_type' => null,
                        'ref_id' => null,
                        'actor_id' => null,
                        'note' => 'Opening balance backfill',
                        'created_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table('stock_movements')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        DB::table('stock_movements')->where('reason', 'INIT')
            ->where('note', 'Opening balance backfill')->delete();
    }
};
