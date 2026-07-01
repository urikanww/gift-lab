<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised by service/model guards when a request is well-formed but violates a
 * business rule given the current state (e.g. "Only DRAFT quotes can be
 * amended", the Proof immutability hook, a line item in a non-procurable
 * state). Mapped to a friendly HTTP 422 in bootstrap/app.php so these stale-
 * state / race conditions never surface as a raw 500. The message is authored
 * to be safe to show to the user.
 */
class DomainRuleException extends RuntimeException
{
}
