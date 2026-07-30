<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feature B: the order id is unified onto `reference` (GL- format). The separate
 * public tracking token is retired — the tracker, QR and broadcast now key off
 * `reference`, gated by the same buyer-email check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->dropUnique(['tracking_code']);
            $table->dropColumn('tracking_code');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->string('tracking_code', 16)->nullable()->unique()->after('id');
        });
    }
};
