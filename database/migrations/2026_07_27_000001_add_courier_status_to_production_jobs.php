<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fields the inbound NinjaVan status webhook writes to. NinjaVan is push-only
 * (no poll API): the webhook is the only source for these columns.
 * `last_courier_status` is a free-text, human-readable label (mapped from
 * NinjaVan's raw status string - see NinjaVanStatusMapper) surfaced to staff
 * for triage of failed/returned parcels; `delivered_at` is set only on the
 * terminal Delivered/Completed event, alongside the job's SHIPPED->CLOSED
 * transition.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_jobs', function (Blueprint $table): void {
            $table->string('last_courier_status')->nullable()->after('label_url');
            $table->timestamp('last_courier_status_at')->nullable()->after('last_courier_status');
            $table->timestamp('delivered_at')->nullable()->after('last_courier_status_at');
        });
    }

    public function down(): void
    {
        Schema::table('production_jobs', function (Blueprint $table): void {
            $table->dropColumn(['last_courier_status', 'last_courier_status_at', 'delivered_at']);
        });
    }
};
