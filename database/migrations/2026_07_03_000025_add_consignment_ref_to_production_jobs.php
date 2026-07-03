<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consignment/tracking reference captured when a job is marked SHIPPED. Makes
 * the "Shipped" transition a deliberate act (the floor must have a real handover
 * reference), so the buyer-facing "on the way" signal is never fired by a stray
 * tap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_jobs', function (Blueprint $table): void {
            $table->string('consignment_ref', 128)->nullable()->after('artwork_ref');
        });
    }

    public function down(): void
    {
        Schema::table('production_jobs', function (Blueprint $table): void {
            $table->dropColumn('consignment_ref');
        });
    }
};
