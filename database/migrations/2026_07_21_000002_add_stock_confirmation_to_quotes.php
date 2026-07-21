<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records the human confirmation that the goods are actually in hand before an
 * order goes to the floor.
 *
 * Most goods here are bought in after the order is placed, so the stock figures
 * the system holds are not the truth and never will be. The automatic check was
 * therefore deciding production on a number nobody maintains. A person saying
 * "I have looked, it is here" is the only reliable gate, and that makes it
 * load-bearing - so it is attributed, not anonymous.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->timestamp('stock_confirmed_at')->nullable()->after('accepted_by');
            $table->foreignId('stock_confirmed_by')->nullable()->after('stock_confirmed_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('stock_confirmed_by');
            $table->dropColumn('stock_confirmed_at');
        });
    }
};
