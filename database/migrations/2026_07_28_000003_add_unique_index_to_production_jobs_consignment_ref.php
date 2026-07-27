<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Residual-collision backstop for the NinjaVan tracking number scheme
 * (NinjaVanTrackingNumber::forJob): every SHIPPED job's consignment_ref must
 * be booked with the courier under a distinct requested_tracking_number, so
 * two jobs can never legitimately share one. A plain unique index is
 * nullable-safe on both this app's targets - MySQL and SQLite both allow any
 * number of NULLs in a UNIQUE column (NULL is never equal to NULL) - so the
 * many jobs that haven't shipped yet (consignment_ref still null) are
 * unaffected; only an actual duplicate ref now fails loud instead of
 * silently orphaning a webhook.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_jobs', function (Blueprint $table): void {
            $table->unique('consignment_ref');
        });
    }

    public function down(): void
    {
        Schema::table('production_jobs', function (Blueprint $table): void {
            $table->dropUnique(['consignment_ref']);
        });
    }
};
