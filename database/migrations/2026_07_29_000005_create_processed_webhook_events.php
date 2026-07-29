<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Event-level idempotency / replay guard for inbound webhooks (L7, L10, L11).
 * A (source, event_key) is recorded once a webhook has been handled; a repeat
 * delivery or a replayed body with the same key is recognised and skipped
 * instead of re-running side effects (double payment capture, duplicate staff
 * alerts, re-broadcasts). event_key is the source's own event id (Stripe) or a
 * hash of the signed body (NinjaVan, which has no reliable event id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processed_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 32);
            $table->string('event_key', 128);
            $table->timestamps();

            $table->unique(['source', 'event_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processed_webhook_events');
    }
};
