<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PDPA consent capture — data layer only.
 *
 * Records who consented and to what: the buyer/staff user at registration
 * (`users.consented_at` + `consent_policy_version`), and the order recipient
 * at checkout (`quotes.recipient_consent_ack_at` + `recipient_consent_version`).
 * Each pair stores the Privacy Policy version in force at the moment of
 * consent (see config/privacy.php), so a later material change can be
 * detected and used to trigger re-consent.
 *
 * All four columns are nullable with no backfill: existing rows are
 * grandfathered as pre-consent-capture (null = consent was never recorded,
 * not "declined"). Nothing in this migration enforces or reads these columns
 * yet — that lands in a later task.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('consented_at')->nullable()->after('password');
            $table->string('consent_policy_version', 32)->nullable()->after('consented_at');
        });

        Schema::table('quotes', function (Blueprint $table): void {
            $table->timestamp('recipient_consent_ack_at')->nullable()->after('idempotency_key');
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
