<?php

declare(strict_types=1);

namespace App\Enums;

enum ReorderState: string
{
    case Draft = 'DRAFT';
    case Approved = 'APPROVED';
    case Ordered = 'ORDERED';
    case Received = 'RECEIVED';
}
