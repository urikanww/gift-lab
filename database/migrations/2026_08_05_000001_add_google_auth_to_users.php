<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Google OAuth (Socialite) sign-in for buyers.
 *
 * - google_id: the provider "sub" (stable Google account id), nullable + unique.
 *   Buyers who signed up with Google carry it; email/password users leave it null.
 *   SQLite/MySQL treat multiple NULLs as distinct, so the unique index does not
 *   collide across the many password-only rows.
 * - password becomes nullable: a Google-only account has no local password.
 *   Auth::attempt never matches a null hash, so password login stays closed for
 *   these accounts until they set one (a future "link" flow).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('google_id')->nullable()->unique()->after('email');
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['google_id']);
            $table->dropColumn('google_id');
            // Best-effort revert; any Google-only rows must be backfilled first.
            $table->string('password')->nullable(false)->change();
        });
    }
};
