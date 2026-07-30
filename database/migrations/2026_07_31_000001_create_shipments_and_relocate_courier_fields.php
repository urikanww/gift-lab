<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 2a — Shipment entity. Moves the six courier fields
 * (consignment_ref UNIQUE, carrier, label_url, last_courier_status,
 * last_courier_status_at, delivered_at) off production_jobs onto a new
 * shipments table (1:1 with jobs in this phase). Behaviour-preserving: one
 * shipment per job, so each job still books its own consignment - only the
 * storage location changes. The webhook now resolves a shipment by
 * consignment_ref, so the unique index moves here too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->string('consignment_ref', 128)->nullable()->unique();
            $table->string('carrier', 32)->nullable();
            $table->string('label_url', 2048)->nullable();
            $table->string('last_courier_status', 255)->nullable();
            $table->timestamp('last_courier_status_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });

        Schema::table('production_jobs', function (Blueprint $table): void {
            $table->foreignId('shipment_id')->nullable()->after('quote_id')->constrained()->nullOnDelete();
        });

        // Backfill: one shipment per EXISTING job (uniform, so 2b grouping is
        // simple), copying its courier fields. Chunk to stay memory-safe on
        // large tables.
        DB::table('production_jobs')->orderBy('id')->chunkById(500, function ($jobs): void {
            foreach ($jobs as $job) {
                $shipmentId = DB::table('shipments')->insertGetId([
                    'quote_id' => $job->quote_id,
                    'consignment_ref' => $job->consignment_ref,
                    'carrier' => $job->carrier,
                    'label_url' => $job->label_url,
                    'last_courier_status' => $job->last_courier_status,
                    'last_courier_status_at' => $job->last_courier_status_at,
                    'delivered_at' => $job->delivered_at,
                    'created_at' => $job->created_at,
                    'updated_at' => $job->updated_at,
                ]);
                DB::table('production_jobs')->where('id', $job->id)->update(['shipment_id' => $shipmentId]);
            }
        });

        Schema::table('production_jobs', function (Blueprint $table): void {
            $table->dropUnique(['consignment_ref']);
        });

        Schema::table('production_jobs', function (Blueprint $table): void {
            $table->dropColumn(['consignment_ref', 'carrier', 'label_url', 'last_courier_status', 'last_courier_status_at', 'delivered_at']);
        });
    }

    public function down(): void
    {
        Schema::table('production_jobs', function (Blueprint $table): void {
            $table->string('consignment_ref', 128)->nullable()->after('artwork_refs');
            $table->string('carrier', 32)->nullable()->after('consignment_ref');
            $table->string('label_url', 2048)->nullable()->after('carrier');
            $table->string('last_courier_status', 255)->nullable()->after('label_url');
            $table->timestamp('last_courier_status_at')->nullable()->after('last_courier_status');
            $table->timestamp('delivered_at')->nullable()->after('last_courier_status_at');
        });

        DB::table('production_jobs')->whereNotNull('shipment_id')->orderBy('id')->chunkById(500, function ($jobs): void {
            foreach ($jobs as $job) {
                $s = DB::table('shipments')->where('id', $job->shipment_id)->first();
                if ($s) {
                    DB::table('production_jobs')->where('id', $job->id)->update([
                        'consignment_ref' => $s->consignment_ref,
                        'carrier' => $s->carrier,
                        'label_url' => $s->label_url,
                        'last_courier_status' => $s->last_courier_status,
                        'last_courier_status_at' => $s->last_courier_status_at,
                        'delivered_at' => $s->delivered_at,
                    ]);
                }
            }
        });

        Schema::table('production_jobs', function (Blueprint $table): void {
            $table->unique('consignment_ref');
            $table->dropConstrainedForeignId('shipment_id');
        });
        Schema::dropIfExists('shipments');
    }
};
