<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentState: string
{
    case Unpaid = 'UNPAID';
    case Partial = 'PARTIAL';
    case Paid = 'PAID';
    case Void = 'VOID';
}
