<?php

declare(strict_types=1);

namespace Tests\Harness\Support;

use Stringable;

/**
 * One invariant failure recorded by the ValidatorAgent. Immutable; the harness
 * collects these and a scenario asserts on the set rather than throwing at the
 * first failure, so one run can surface every break at once.
 */
final class Violation implements Stringable
{
    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly ?string $state = null,
    ) {}

    public function __toString(): string
    {
        return "[{$this->code}] {$this->message} (state: {$this->state})";
    }
}
