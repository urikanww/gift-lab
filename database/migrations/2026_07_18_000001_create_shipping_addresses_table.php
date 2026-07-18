<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_addresses', function (Blueprint $table): void {
            $table->id();
            // One ship-to per quote. Defaults are seeded from companies.address
            // but staff edit them per order (recipient, phone, structured lines
            // — the fields a courier API needs).
            $table->foreignId('quote_id')->unique()->constrained('quotes')->cascadeOnDelete();
            $table->string('recipient_name');
            $table->string('phone', 32);
            $table->string('email')->nullable();
            $table->string('line1');
            $table->string('line2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postal_code', 16);
            $table->char('country', 2)->default('SG');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_addresses');
    }
};
