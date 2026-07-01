<?php

declare(strict_types=1);

namespace App\Enums;

enum PublishState: string
{
    case Pending = 'PENDING';
    case ReadyToApprove = 'READY_TO_APPROVE';
    case Published = 'PUBLISHED';
    case CannotPublish = 'CANNOT_PUBLISH';

    public function isPublic(): bool
    {
        return $this === self::Published;
    }
}
