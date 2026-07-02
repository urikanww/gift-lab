<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MODEL_3D items arrive from source APIs with placeholder filament/weight
 * estimates (the APIs don't provide them). Auto-publish must never ship an
 * item whose production estimates nobody has confirmed, so ingest marks the
 * row unverified and staff (or a future slicer pass) flips this flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('estimates_verified')->default(false)->after('est_grams');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('estimates_verified');
        });
    }
};
