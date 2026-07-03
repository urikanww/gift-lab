<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The staff dashboard activity feed reads `ORDER BY created_at DESC LIMIT 20`.
// audit_logs is indexed on event/user/auditable but not created_at, so the feed
// would sort the whole (never-purged) table. Add the index.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex(['created_at']);
        });
    }
};
