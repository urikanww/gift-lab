<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dashboard KPIs + the 8-week trend roll up quotes by created_at (this-week
 * count, this-month booked sum, weekly buckets). quotes indexes state and
 * company_id but not created_at, so these range scans had no index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->dropIndex(['created_at']);
        });
    }
};
