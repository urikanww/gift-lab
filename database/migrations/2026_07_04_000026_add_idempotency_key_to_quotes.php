<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client-generated replay token for quote submission (audit A12): a
 * double-click or retry-on-slow-network re-sends the same key and receives the
 * original quote instead of creating a duplicate draft. Unique per company so
 * one buyer's key can never collide into another tenant's quote.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->string('idempotency_key', 64)->nullable()->after('tracking_code');
            $table->unique(['company_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->dropUnique(['company_id', 'idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
