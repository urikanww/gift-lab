<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 3D model licence gate (spec 6.5). Only commercial-OK licences may publish.
 */
enum License: string
{
    case Cc0 = 'CC0';
    case CcBy = 'CC_BY';
    case Owned = 'OWNED';
    case Blocked = 'BLOCKED';

    public function isCommercialOk(): bool
    {
        return match ($this) {
            self::Cc0, self::CcBy, self::Owned => true,
            self::Blocked => false,
        };
    }

    public function requiresCreatorCredit(): bool
    {
        return $this === self::CcBy;
    }
}
