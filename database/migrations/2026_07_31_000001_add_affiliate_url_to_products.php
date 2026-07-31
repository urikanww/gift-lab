<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff-entered Shopee affiliate link for a product. Deliberately separate from
 * source_url (the plain procurement/reorder link): affiliate_url powers the
 * "check stock on Shopee" button on the staff order-detail page and carries the
 * affiliate deeplink, while source_url stays the plain listing used for buy-list
 * procurement. Staff-gated everywhere it is exposed - never sent to buyers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('affiliate_url', 2048)->nullable()->after('source_product_id')
                ->comment('Staff-only Shopee affiliate deeplink for the order-detail stock-check button');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('affiliate_url');
        });
    }
};
