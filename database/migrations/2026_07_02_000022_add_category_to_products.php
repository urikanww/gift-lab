<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public marketplace category (drinkware, bags, …) — how buyers browse.
 * Orthogonal to `class`, which stays the internal production taxonomy.
 * Nullable so the model saving-hook (or catalogue:categorize) fills it in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('category', 32)->nullable()->index()->after('class');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['category']);
            $table->dropColumn('category');
        });
    }
};
