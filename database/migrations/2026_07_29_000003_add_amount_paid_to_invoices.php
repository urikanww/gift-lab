<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H3/M21: a PARTIAL (or PAID) invoice now records HOW MUCH was actually
 * collected, so a cancel/credit pays back only the received amount instead of
 * the full invoice, and staff can see the balance still owed. Nullable: legacy
 * invoices predate the column; the credit path falls back to the full amount
 * only when it is null (a legacy PAID invoice), never for a PARTIAL (which is
 * required to carry an amount from now on).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->decimal('amount_paid', 10, 2)->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn('amount_paid');
        });
    }
};
