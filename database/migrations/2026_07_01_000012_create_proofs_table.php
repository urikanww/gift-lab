<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Formal proof + immutable sign-off (spec 6.3, gate 1). Once state=APPROVED the
 * row is immutable at the application layer: any artwork/product change creates
 * a NEW proof (incremented version) bound to the new artwork version, rather
 * than editing an approved proof. approved_by/approved_at are the audit anchors.
 *
 * The approved artwork_version_ref IS the production print file (spec 7): no
 * separate re-processing step, so the floor prints exactly what was signed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proofs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quote_id')->constrained('quotes')->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->string('artwork_version_ref')->comment('object-store key; = production print file when approved');
            $table->enum('state', ['SENT', 'CHANGES_REQUESTED', 'APPROVED'])->default('SENT');
            $table->foreignId('approved_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('quote_id');
            $table->index('state');
            $table->index(['quote_id', 'state']);
            $table->unique(['quote_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proofs');
    }
};
