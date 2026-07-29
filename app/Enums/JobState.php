<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Production job lifecycle (spec 5.4). Honest customer-facing stages.
 */
enum JobState: string
{
    case Ready = 'READY';
    case InProduction = 'IN_PRODUCTION';
    case Shipped = 'SHIPPED';
    case Closed = 'CLOSED';
    // A shipped parcel the courier returned that staff chose to cancel & credit
    // (M15). Terminal and distinct from Closed so a returned box is never faked
    // as delivered: on a multi-parcel order only THIS parcel is voided/restocked
    // while its siblings continue. Also drops the job off the awaiting-delivery
    // board (which lists SHIPPED only).
    case Returned = 'RETURNED';

    /**
     * @return array<int, self>
     */
    public function nextStates(): array
    {
        return match ($this) {
            self::Ready => [self::InProduction],
            self::InProduction => [self::Shipped],
            // Closed MUST stay index [0]: QueueService::advanceNext() picks
            // nextStates()[0] for its one-tap advance, and the webhook's
            // delivered-idempotency path relies on the same ordering.
            // InProduction is the reship edge - a returned/failed parcel
            // (QueueService::resolveReturn's 'reship' disposition) goes back
            // to IN_PRODUCTION to re-queue for a fresh shipment, rather than
            // being stuck unable to leave SHIPPED.
            // Returned appended last so it never shifts index [0] (Closed) that
            // advanceNext()/the webhook rely on. Shipped→Returned is the
            // cancel-&-credit-this-parcel edge (M15).
            self::Shipped => [self::Closed, self::InProduction, self::Returned],
            self::Closed => [],
            self::Returned => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->nextStates(), true);
    }
}
