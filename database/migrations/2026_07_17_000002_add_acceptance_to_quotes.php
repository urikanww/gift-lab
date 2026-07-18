<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            // Records that the buyer agreed to the price (and who/when), even when
            // the slim path skips the ACCEPTED dwell. Also discriminates the slim
            // vs existing rejection behavior in requestProofChanges.
            $table->timestamp('accepted_at')->nullable()->after('price_snapshot_at');
            $table->foreignId('accepted_by')->nullable()->after('accepted_at')
                ->constrained('users')->nullOnDelete();
        });

        // Legacy backfill: quotes already past the acceptance point predate this
        // column, so accepted_at is null even though they were accepted. Seed it from
        // the price snapshot (frozen at send, just before acceptance) or updated_at so
        // the slim-vs-accepted discriminator in requestProofChanges/approveProof treats
        // them as the accepted path. accepted_by stays null (the original actor is
        // unknown for legacy rows).
        DB::table('quotes')
            ->whereNull('accepted_at')
            ->whereIn('state', [
                'ACCEPTED', 'PROOFING', 'PROOF_APPROVED', 'INVOICED',
                'CONFIRMED', 'PROCURING', 'READY', 'CLOSED',
            ])
            ->update(['accepted_at' => DB::raw('COALESCE(price_snapshot_at, updated_at)')]);
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('accepted_by');
            $table->dropColumn('accepted_at');
        });
    }
};
