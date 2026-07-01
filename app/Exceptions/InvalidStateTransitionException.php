<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when code attempts an illegal state-machine transition. Surfaced as a
 * 422 by the exception handler; also guards against programmer error.
 */
class InvalidStateTransitionException extends RuntimeException
{
    public static function between(string $entity, string $from, string $to): self
    {
        return new self("Illegal {$entity} transition: {$from} -> {$to}.");
    }
}
