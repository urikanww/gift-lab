<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The discover-3d nightly sweep is browse-only now; the legacy per-keyword
 * fallback and its catalogue/discovery_keywords config were removed. Drop the
 * row from already-seeded databases so it no longer clutters the pricing editor.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('pricing_configs')
            ->where('group', 'catalogue')
            ->where('key', 'discovery_keywords')
            ->delete();
    }

    public function down(): void
    {
        // Restore the old default so the migration is reversible.
        DB::table('pricing_configs')->updateOrInsert(
            ['group' => 'catalogue', 'key' => 'discovery_keywords'],
            [
                'value' => json_encode([
                    'phone stand', 'desk organizer', 'cable holder', 'name plate',
                    'keychain', 'pen holder', 'card holder', 'coaster',
                    'headphone stand', 'plant pot', 'luggage tag', 'bag hook',
                ]),
                'label' => 'Keywords swept nightly by catalogue:discover-3d',
                'is_money' => false,
                'currency' => 'SGD',
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }
};
