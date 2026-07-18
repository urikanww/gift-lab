<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Wraps an unrecoverable courier API failure - a non-retryable error response,
 * a malformed payload, or an unreachable endpoint when creating a shipment with
 * the carrier. Raised by CourierClient implementations so callers can surface a
 * booking failure without leaking transport-level details.
 */
class CourierException extends RuntimeException
{
}
