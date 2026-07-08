<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soft-delete cascade gap fix: Quote/Product/Company/Model3D/User use
 * SoftDeletes, but their children did not - a soft-deleted parent left live
 * children (orphaned rows, cancelled quotes' jobs lingering on the floor queue).
 * Adds deleted_at to each child so the model-level cascade can soft-delete them.
 */
return new class extends Migration
{
    /**
     * @var array<int, string>
     */
    private array $tables = [
        'line_items',
        'proofs',
        'production_jobs',
        'purchase_orders',
        'variants',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->softDeletes();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropSoftDeletes();
            });
        }
    }
};
