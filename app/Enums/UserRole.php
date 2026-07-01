<?php

declare(strict_types=1);

namespace App\Enums;

enum UserRole: string
{
    case Buyer = 'buyer';
    case StaffAdmin = 'staff_admin';
    case Superadmin = 'superadmin';

    public function isStaff(): bool
    {
        return $this === self::StaffAdmin || $this === self::Superadmin;
    }

    public function canManagePricing(): bool
    {
        return $this === self::Superadmin;
    }
}
