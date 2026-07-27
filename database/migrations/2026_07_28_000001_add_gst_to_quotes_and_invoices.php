<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Singapore GST as a first-class itemised line, on both quotes and invoices.
 * gst_amount is the money amount (12,2, same shape as every other money
 * column); gst_rate is the pct actually applied (5,2 - e.g. 9.00), snapshotted
 * per-row so a later edit to the configurable tax.gst_pct rate never
 * reprices a historical document. Default 9 on both columns backfills
 * existing rows to the rate the app has always implicitly assumed, rather
 * than leaving them at a misleading 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->decimal('gst_amount', 12, 2)->default(0)->after('delivery');
            $table->decimal('gst_rate', 5, 2)->default(9)->after('gst_amount');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->decimal('gst_amount', 12, 2)->default(0)->after('amount');
            $table->decimal('gst_rate', 5, 2)->default(9)->after('gst_amount');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->dropColumn(['gst_amount', 'gst_rate']);
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn(['gst_amount', 'gst_rate']);
        });
    }
};
