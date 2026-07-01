<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dynamic pricing configuration, owned by the superadmin dashboard and read at
 * quote time. No hardcoded margins/fees anywhere in code (spec principle 5).
 * value is JSON so a single row can hold a scalar (margin %), a money amount,
 * or a structure (delivery table, per-method print cost, bulk thresholds).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_configs', function (Blueprint $table): void {
            $table->id();
            $table->string('group')->comment('margin | fee | delivery | threshold | print_cost');
            $table->string('key');
            $table->json('value');
            $table->string('label')->nullable();
            $table->boolean('is_money')->default(false);
            $table->char('currency', 3)->default('SGD');
            $table->foreignId('updated_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['group', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_configs');
    }
};
