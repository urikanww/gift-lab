<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\PaymentState;
use App\Enums\QuoteState;
use App\Exceptions\FeatureNotEnabledException;
use App\Models\PricingConfig;
use App\Models\PurchaseOrder;
use App\Models\Quote;
use App\Services\AuditLogger;
use App\Services\Payment\Contracts\PaymentGateway;
use App\Services\QuoteService;
use RuntimeException;

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
            throw new RuntimeException('Quote must be PROOF_APPROVED before payment.');
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
        $existing = $quote->purchaseOrders()->first();
        if ($existing !== null || $quote->state !== QuoteState::ProofApproved) {
            return $existing ?? $quote->purchaseOrders()->firstOrFail();
        }

        $po = $this->quotes->issuePurchaseOrder($quote, 'B2C-'.$quote->id, $reference, 'PREPAID');
        $po->payment_state = PaymentState::Paid;
        $po->save();

        $this->audit->log($po, 'payment.captured', null, ['reference' => $reference, 'amount' => $po->amount]);

        $this->quotes->procure($quote->fresh());

        return $po;
    }
}
