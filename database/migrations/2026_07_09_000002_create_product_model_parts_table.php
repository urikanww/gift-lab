<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A MODEL_3D product can be a multi-part figure (e.g. head, body, arms, legs)
 * whose source ships several printable STL files. Ingest previously kept only
 * the single largest file and discarded the rest, so the other parts could not
 * be viewed or produced. This table persists every downloaded part alongside
 * the primary mesh (products.model_file_ref) so superadmins can inspect the
 * complete set and the floor can print each piece.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_model_parts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            // Human label (from the source filename, e.g. "Head"); null → "Part N".
            $table->string('label')->nullable();
            // Object-store key on the local disk (models3d/{source}-{id}-partN.stl).
            $table->string('file_ref');
            // Mesh complexity, used to pick/annotate the primary part.
            $table->unsignedInteger('triangle_count')->nullable();
            // The largest part is mirrored onto products.model_file_ref.
            $table->boolean('is_primary')->default(false);
            // Display order (source order).
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_model_parts');
    }
};
