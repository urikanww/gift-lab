<?php

declare(strict_types=1);

namespace Tests\Harness\Agents;

use App\Enums\QuoteState;
use App\Models\StockMovement;
use InvalidArgumentException;
use Tests\Harness\Support\HarnessContext;
use Tests\Harness\Support\Violation;

/**
 * Read-only test oracle. Never mutates app state. Records a Violation instead of
 * throwing, so a scenario can drive the whole journey and then assert on the
 * complete set of invariant failures.
 */
final class ValidatorAgent
{
    /** @var array<int, Violation> */
    private array $violations = [];

    public function __construct(private readonly HarnessContext $ctx) {}

    /**
     * Legality is defined by the state machine itself, so this stays correct if
     * QuoteState changes.
     */
    public function assertLegalTransition(QuoteState $from, QuoteState $to): void
    {
        if (! $from->canTransitionTo($to)) {
            $this->violations[] = new Violation(
                'ILLEGAL_TRANSITION',
                "{$from->value} -> {$to->value} is not a legal quote transition",
                $from->value,
            );
        }
    }

    public function check(string $invariant): void
    {
        match ($invariant) {
            'INVOICE_MATCHES_PRODUCED' => $this->checkInvoiceMatchesProduced(),
            'LEDGER_BALANCED_AFTER_CANCEL' => $this->checkLedgerBalancedAfterCancel(),
            default => throw new InvalidArgumentException("Unknown invariant: {$invariant}"),
        };
    }

    /** @return array<int, Violation> */
    public function violations(): array
    {
        return $this->violations;
    }

    /**
     * Every non-dropped line must bill the quantity it actually produces. A
     * produced_qty of 0 means procurement has not run for the line yet, so it is
     * not asserted.
     */
    private function checkInvoiceMatchesProduced(): void
    {
        $quote = $this->ctx->quote?->fresh(['lineItems']);

        foreach ($quote?->lineItems ?? [] as $line) {
            if ($line->line_state->value === 'DROPPED') {
                continue;
            }

            $produced = (int) $line->procured_qty;

            if ($produced > 0 && (int) $line->qty !== $produced) {
                $this->violations[] = new Violation(
                    'INVOICE_MATCHES_PRODUCED',
                    "Line {$line->id}: billed qty {$line->qty} != produced qty {$produced}",
                    $quote?->state->value,
                );
            }
        }
    }

    /**
     * After a cancel, every variant-backed line's stock movements must net to
     * zero (SALE consumed, RETURN gave back the same). Lines with no variant are
     * skipped here and handled by the scenario that owns them.
     */
    private function checkLedgerBalancedAfterCancel(): void
    {
        $quote = $this->ctx->quote?->fresh(['lineItems.variant']);

        foreach ($quote?->lineItems ?? [] as $line) {
            if ($line->variant === null) {
                continue;
            }

            $net = (int) StockMovement::query()
                ->where('ref_type', $line->getMorphClass())
                ->where('ref_id', $line->getKey())
                ->sum('delta');

            if ($net !== 0) {
                $this->violations[] = new Violation(
                    'LEDGER_BALANCED_AFTER_CANCEL',
                    "Line {$line->id}: net stock movement {$net} != 0 after cancel",
                    $quote?->state->value,
                );
            }
        }
    }
}
