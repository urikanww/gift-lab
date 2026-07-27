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
            self::Shipped => [self::Closed, self::InProduction],
            self::Closed => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->nextStates(), true);
    }
}
