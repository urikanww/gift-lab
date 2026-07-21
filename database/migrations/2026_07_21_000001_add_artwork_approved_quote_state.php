<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds ARTWORK_APPROVED to the quotes.state column.
 *
 * The artwork-first route let a buyer approve artwork without ever being shown
 * the price, and approval then back-filled acceptance silently. Separating the
 * two approvals needs a state meaning "artwork signed off, price outstanding",
 * or PROOF_APPROVED has to carry both meanings and invoicing cannot tell them
 * apart.
 *
 * Driver-aware, mirroring the PO_ISSUED -> INVOICED migration: MySQL rebuilds
 * the native ENUM via raw ALTER, other drivers (SQLite in the test suite)
 * rebuild the CHECK constraint via ->change(). Widening only - no existing row
 * changes value, so there is no backfill and down() is a pure narrowing.
 */
return new class extends Migration
{
    /**
     * @var array<int, string>
     */
    private array $withArtworkApproved = [
        'DRAFT',
        'SENT',
        'CHANGES_REQUESTED',
        'ACCEPTED',
        'PROOFING',
        'ARTWORK_APPROVED',
        'PROOF_APPROVED',
        'INVOICED',
        'CONFIRMED',
        'PROCURING',
        'READY',
        'CLOSED',
        'CANCELLED',
    ];

    /**
     * @var array<int, string>
     */
    private array $withoutArtworkApproved = [
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

    public function up(): void
    {
        $this->setStates($this->withArtworkApproved);
    }

    public function down(): void
    {
        // Any order mid-flight on the artwork-first route would violate the
        // narrowed constraint. Park them back in PROOFING: the artwork approval
        // is still recorded on the proof itself, so nothing is lost that cannot
        // be re-derived, and the order remains actionable.
        DB::table('quotes')->where('state', 'ARTWORK_APPROVED')->update(['state' => 'PROOFING']);

        $this->setStates($this->withoutArtworkApproved);
    }

    /**
     * @param  array<int, string>  $states
     */
    private function setStates(array $states): void
    {
        if (DB::getDriverName() === 'mysql') {
            $list = implode(',', array_map(static fn (string $s): string => "'".$s."'", $states));
            DB::statement("ALTER TABLE quotes MODIFY COLUMN state ENUM({$list}) NOT NULL DEFAULT 'DRAFT'");

            return;
        }

        Schema::table('quotes', function (Blueprint $table) use ($states): void {
            $table->enum('state', $states)->default('DRAFT')->change();
        });
    }
};
