<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown when the upstream payment provider (Stripe) fails to create or process
 * a checkout - network error, API error, or misconfiguration. Wraps the raw
 * provider exception so the boundary never leaks provider internals to the
 * caller; surfaced as a 502 by the exception handler.
 */
class PaymentGatewayException extends RuntimeException
{
    public static function checkoutFailed(Throwable $previous): self
    {
        return new self('Payment provider could not start checkout.', 0, $previous);
    }
}
