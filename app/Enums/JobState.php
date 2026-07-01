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
            self::Shipped => [self::Closed],
            self::Closed => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->nextStates(), true);
    }
}
