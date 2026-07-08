<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Public catalogue URLs use a human-readable slug instead of the numeric id
 * ("/products/geometric-desk-vase" not "/products/216") - friendlier links
 * and no catalogue enumeration via sequential ids. Slugs are generated once
 * from the name and stay stable across renames so shared links never break.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('slug')->nullable()->unique()->after('name');
        });

        // Backfill existing rows; suffix the id on collision (unique index).
        foreach (DB::table('products')->select('id', 'name')->orderBy('id')->cursor() as $row) {
            $base = Str::slug((string) $row->name) ?: 'product';
            $slug = $base;

            if (DB::table('products')->where('slug', $slug)->exists()) {
                $slug = "{$base}-{$row->id}";
            }

            DB::table('products')->where('id', $row->id)->update(['slug' => $slug]);
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
