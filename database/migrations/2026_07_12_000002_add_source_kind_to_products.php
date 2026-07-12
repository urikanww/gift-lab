<?php

declare(strict_types=1);

use App\Support\SourceKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Persisted, indexed source provenance label for the catalogue gate's Source
 * filter. Derived from source_url via App\Support\SourceKind and kept in sync in
 * the Product saving hook. Backfilled here for existing rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('source_kind', 20)->nullable()->after('source_url')
                ->comment('marketplace|local|makerworld|thingiverse|cults3d|manual');
            $table->index('source_kind');
        });

        DB::table('products')->select('id', 'source_url')->orderBy('id')
            ->chunk(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('products')->where('id', $row->id)
                        ->update(['source_kind' => SourceKind::fromUrl($row->source_url)]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['source_kind']);
            $table->dropColumn('source_kind');
        });
    }
};
