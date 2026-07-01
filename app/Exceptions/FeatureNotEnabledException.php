<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a deferred (spec Phase 2) capability is invoked while the launch
 * scope is spine-only. This is an intentional guard, not a placeholder: the
 * interface contract exists, the strategy is registered, and the boundary is
 * enforced explicitly so a mis-routed line fails loudly rather than silently.
 */
class FeatureNotEnabledException extends RuntimeException
{
    public static function make(string $feature): self
    {
        return new self("{$feature} is not enabled in the current launch scope (spec Phase 2).");
    }
}
