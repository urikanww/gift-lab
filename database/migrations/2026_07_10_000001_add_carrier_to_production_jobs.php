<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Courier a job was shipped with, captured alongside consignment_ref at the
 * SHIPPED transition. Powers the buyer-facing carrier tracking link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_jobs', function (Blueprint $table): void {
            $table->string('carrier', 32)->nullable()->after('consignment_ref');
        });
    }

    public function down(): void
    {
        Schema::table('production_jobs', function (Blueprint $table): void {
            $table->dropColumn('carrier');
        });
    }
};
