<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multiple ranked buy links per blank (UV blank library): local SG primary for
 * speed + marketplace plain-URL backups. `source_url` stays as the derived
 * primary so existing buy-list / "View source" consumers keep working.
 * Shape: [{label, url, kind: local|marketplace, price, currency, last_checked}]
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->json('source_links')->nullable()->after('source_url')
                ->comment('[{label,url,kind,price,currency,last_checked}] - buy links per blank');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('source_links');
        });
    }
};
