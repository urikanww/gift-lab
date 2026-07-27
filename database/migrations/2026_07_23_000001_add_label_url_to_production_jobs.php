<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Printable waybill URL returned by the courier alongside the tracking number
 * at booking time (parsed but previously discarded), captured at the SHIPPED
 * transition alongside consignment_ref/carrier so staff can reprint the label
 * without re-hitting the courier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_jobs', function (Blueprint $table): void {
            $table->string('label_url', 2048)->nullable()->after('carrier');
        });
    }

    public function down(): void
    {
        Schema::table('production_jobs', function (Blueprint $table): void {
            $table->dropColumn('label_url');
        });
    }
};
