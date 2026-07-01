<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\PaymentState;
use App\Enums\QuoteState;
use App\Exceptions\DomainRuleException;
use App\Exceptions\FeatureNotEnabledException;
use App\Models\PricingConfig;
use App\Models\PurchaseOrder;
use App\Models\Quote;
use App\Services\AuditLogger;
use App\Services\Payment\Contracts\PaymentGateway;
use App\Services\QuoteService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * B2C "pay now": a proof-approved quote is paid up front, then feeds the same
 * production spine as B2B (spec §14). Gated by the superadmin pay-now/cutoff
 * config. Capture is immediate (fixture) or via Stripe webhook; confirmPaid is
 * idempotent so a duplicate webhook is harmless.
 */
final class PaymentService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly QuoteService $quotes,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @return array{checkout: array{id: string, url: string}, paid: bool}
     */
    public function payNow(Quote $quote): array
    {
        $cutoff = (array) PricingConfig::value('config', 'pay_now_cutoff', ['b2c_enabled' => false]);
        if (empty($cutoff['b2c_enabled'])) {
            throw FeatureNotEnabledException::make('B2C pay-now');
        }

        if ($quote->state !== QuoteState::ProofApproved) {
            throw new DomainRuleException('Quote must be PROOF_APPROVED before payment.');
        }

        $checkout = $this->gateway->createCheckout($quote);
        $paid = false;

        if ($this->gateway->confirmsImmediately()) {
            $this->confirmPaid($quote, $checkout['id']);
            $paid = true;
        }

        return ['checkout' => $checkout, 'paid' => $paid];
    }

    /**
     * Capture confirmation → issue a PAID PO, confirm, and procure into the
     * shared queue. Idempotent: a second call for an already-processed quote
     * returns the existing PO without re-transitioning.
     */
    public function confirmPaid(Quote $quote, string $reference): PurchaseOrder
    {
        // TOCTOU hardening: Stripe retries deliver checkout.session.completed more
        // than once. We serialize concurrent deliveries by taking a row-level
        // lock on the quote for the whole capture, so the second delivery blocks
        // until the first commits, then observes the freshly-created PO and
        // returns it idempotently. A residual unique-violation on po_ref (a
        // delivery that raced in via a non-locked path) is collapsed into success.
        return DB::transaction(function () use ($quote, $reference): PurchaseOrder {
            /** @var Quote $locked */
            $locked = Quote::query()
                ->whereKey($quote->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $existing = $locked->purchaseOrders()->first();
            if ($existing !== null || $locked->state !== QuoteState::ProofApproved) {
                return $existing ?? $locked->purchaseOrders()->firstOrFail();
            }

            try {
                $po = $this->quotes->issuePurchaseOrder($locked, 'B2C-'.$locked->id, $reference, 'PREPAID');
            } catch (QueryException $e) {
                if ($this->isUniqueViolation($e) && ($winner = $locked->purchaseOrders()->first()) !== null) {
                    Log::warning('Duplicate payment capture collapsed to existing PO.', [
                        'quote_id' => $locked->id,
                        'reference' => $reference,
                        'error' => $e->getMessage(),
                    ]);

                    return $winner;
                }

                Log::error('Payment capture failed to issue purchase order.', [
                    'quote_id' => $locked->id,
                    'reference' => $reference,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }

            $po->payment_state = PaymentState::Paid;
            $po->save();

            $this->audit->log($po, 'payment.captured', null, ['reference' => $reference, 'amount' => $po->amount]);

            $this->quotes->procure($locked->fresh());

            return $po;
        });
    }

    /**
     * Detect a DB integrity-constraint (unique) violation across MySQL/Postgres
     * without coupling to a single driver's error code.
     */
    private function isUniqueViolation(QueryException $e): bool
    {
        // SQLSTATE 23000 (MySQL) / 23505 (Postgres) = integrity constraint violation.
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());

        return in_array($sqlState, ['23000', '23505'], true);
    }
}
