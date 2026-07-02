<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Source image/page URLs from live feeds exceed varchar(255) — Cults3D image
 * URLs are proxy-wrapped (resize service + original URL nested inside) and
 * routinely run 300+ chars. TEXT columns; these are never indexed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->text('image_url')->nullable()->change();
            $table->text('source_url')->nullable()->change();
            $table->text('model_file_ref')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('image_url')->nullable()->change();
            $table->string('source_url')->nullable()->change();
            $table->string('model_file_ref')->nullable()->change();
        });
    }
};
