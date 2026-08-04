<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('consented_at')->nullable()->after('password');
            $table->string('consent_policy_version', 32)->nullable()->after('consented_at');
        });

        Schema::table('quotes', function (Blueprint $table): void {
            $table->timestamp('recipient_consent_ack_at')->nullable();
            $table->string('recipient_consent_version', 32)->nullable()->after('recipient_consent_ack_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['consented_at', 'consent_policy_version']);
        });

        Schema::table('quotes', function (Blueprint $table): void {
            $table->dropColumn(['recipient_consent_ack_at', 'recipient_consent_version']);
        });
    }
};
