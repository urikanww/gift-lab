<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task 2 of the quote-spine reshape. Two structural changes:
 *
 *  1. Make the quote state value 'INVOICED' legal at the DB level (replacing
 *     'PO_ISSUED'). Task 1 already renamed the QuoteState enum case in PHP, but
 *     the quotes.state column still allows only 'PO_ISSUED', so saving
 *     'INVOICED' is rejected by the ENUM (MySQL) / CHECK (SQLite) constraint.
 *  2. Rename the purchase_orders table -> invoices (a rename, not drop/recreate,
 *     so existing rows survive).
 *
 * Driver-aware: MySQL rebuilds the native ENUM via raw ALTER; other drivers
 * (SQLite in the test suite) rebuild the CHECK constraint via ->change().
 */
return new class extends Migration
{
    /**
     * Allowed state values after this migration (PO_ISSUED -> INVOICED).
     *
     * @var array<int, string>
     */
    private array $newStates = [
        'DRAFT',
        'SENT',
        'CHANGES_REQUESTED',
        'ACCEPTED',
        'PROOFING',
        'PROOF_APPROVED',
        'INVOICED',
        'CONFIRMED',
        'PROCURING',
        'READY',
        'CLOSED',
        'CANCELLED',
    ];

    /**
     * Original allowed state values (with PO_ISSUED) for the down migration.
     *
     * @var array<int, string>
     */
    private array $oldStates = [
        'DRAFT',
        'SENT',
        'CHANGES_REQUESTED',
        'ACCEPTED',
        'PROOFING',
        'PROOF_APPROVED',
        'PO_ISSUED',
        'CONFIRMED',
        'PROCURING',
        'READY',
        'CLOSED',
        'CANCELLED',
    ];

    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            // Widen to a superset that accepts both values so the backfill can
            // move rows without tripping the constraint, then narrow to the
            // final list (PO_ISSUED dropped).
            $this->mysqlModifyState([...$this->oldStates, 'INVOICED']);
            DB::table('quotes')->where('state', 'PO_ISSUED')->update(['state' => 'INVOICED']);
            $this->mysqlModifyState($this->newStates);
        } else {
            // SQLite enforces the CHECK on the backfill UPDATE itself, so the
            // constraint must already accept 'INVOICED' before we write it.
            // Widen to a superset (old list + INVOICED) first, then backfill,
            // then narrow to the final list. Going straight to newStates before
            // the backfill would make the table-rebuild's INSERT...SELECT copy
            // the still-PO_ISSUED rows into a table whose CHECK rejects them.
            Schema::table('quotes', function (Blueprint $table): void {
                $table->enum('state', [...$this->oldStates, 'INVOICED'])->default('DRAFT')->change();
            });
            DB::table('quotes')->where('state', 'PO_ISSUED')->update(['state' => 'INVOICED']);
            Schema::table('quotes', function (Blueprint $table): void {
                $table->enum('state', $this->newStates)->default('DRAFT')->change();
            });
        }

        Schema::rename('purchase_orders', 'invoices');
    }

    public function down(): void
    {
        Schema::rename('invoices', 'purchase_orders');

        if (DB::getDriverName() === 'mysql') {
            $this->mysqlModifyState([...$this->oldStates, 'INVOICED']);
            DB::table('quotes')->where('state', 'INVOICED')->update(['state' => 'PO_ISSUED']);
            $this->mysqlModifyState($this->oldStates);
        } else {
            // Same widen-first ordering as up(): the CHECK must accept both
            // values before the reverse backfill UPDATE runs under SQLite.
            Schema::table('quotes', function (Blueprint $table): void {
                $table->enum('state', [...$this->oldStates, 'INVOICED'])->default('DRAFT')->change();
            });
            DB::table('quotes')->where('state', 'INVOICED')->update(['state' => 'PO_ISSUED']);
            Schema::table('quotes', function (Blueprint $table): void {
                $table->enum('state', $this->oldStates)->default('DRAFT')->change();
            });
        }
    }

    /**
     * Rebuild the native MySQL ENUM for quotes.state, preserving NOT NULL and
     * the DRAFT default.
     *
     * @param  array<int, string>  $states
     */
    private function mysqlModifyState(array $states): void
    {
        $list = implode(',', array_map(
            static fn (string $s): string => "'".$s."'",
            $states,
        ));

        DB::statement("ALTER TABLE quotes MODIFY COLUMN state ENUM({$list}) NOT NULL DEFAULT 'DRAFT'");
    }
};
