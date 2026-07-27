<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

// Task 12 (migration fix): 2026_07_11_141706_create_jobs_table's up() only
// creates `jobs` when absent (a guarded no-op on environments where the queue
// tables pre-exist outside migration history) - so this migration never
// reliably OWNS the table. Its down() must NOT unconditionally drop it, or a
// rollback on such an environment silently destroys the framework's live
// queue table (every pending/in-flight job).

it('does not drop the framework jobs table on a rollback', function (): void {
    expect(Schema::hasTable('jobs'))->toBeTrue();

    $migration = require database_path('migrations/2026_07_11_141706_create_jobs_table.php');
    $migration->down();

    expect(Schema::hasTable('jobs'))->toBeTrue();
});
