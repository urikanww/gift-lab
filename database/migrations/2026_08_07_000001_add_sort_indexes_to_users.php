<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The admin users list (AdminUserController@index) sorts by name (default) and
 * by created_at. Neither column was indexed, so ORDER BY on a large users table
 * falls back to a filesort. These single-column indexes back the sort.
 *
 * Not indexed here: the free-text search is a leading-wildcard LIKE on
 * name/email/company.name, which a B-tree index cannot serve - a full-text
 * index is the right tool if/when search latency is measured to matter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->index('name');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['name']);
            $table->dropIndex(['created_at']);
        });
    }
};
