<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 3D model catalogue source (Phase 2 track). Pulled from Thingiverse / Cults3D
 * APIs or owned/commissioned. Licence gate: only CC0 / CC_BY publish; CC_BY
 * stores creator credit. Schema built now so Phase 2 plugs in without a
 * re-migration; the API client is stubbed until credentials are provisioned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model3ds', function (Blueprint $table): void {
            $table->id();
            $table->enum('source', ['THINGIVERSE', 'CULTS3D', 'OWNED']);
            $table->string('source_id')->nullable();
            $table->enum('license', ['CC0', 'CC_BY', 'OWNED', 'BLOCKED'])->default('BLOCKED');
            $table->string('creator_credit')->nullable();
            $table->string('file_ref')->nullable()->comment('object-store key for model file');
            $table->enum('publish_state', [
                'PENDING',
                'READY_TO_APPROVE',
                'PUBLISHED',
                'CANNOT_PUBLISH',
            ])->default('PENDING');
            $table->json('cannot_publish_reasons')->nullable();
            $table->foreignId('created_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['source', 'source_id']);
            $table->index('license');
            $table->index('publish_state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model3ds');
    }
};
