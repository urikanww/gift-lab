<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('accepted_by');
            $table->dropColumn('accepted_at');
        });
    }
};
