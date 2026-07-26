<?php

declare(strict_types=1);

namespace Tests\Harness\Support;

use App\Models\Quote;
use App\Models\User;
use Tests\TestCase;

/**
 * Per-scenario shared state: the authenticated actors, the quote currently
 * under test, and an ordered log of the actions the agents performed (useful
 * when a scenario fails and you need the journey that led there).
 *
 * `staff` is a staff_admin used by both StaffAgent and ProductionAgent (the
 * production/procurement routes are staff-gated). `buyer` belongs to the same
 * company as the quote and drives the customer-facing endpoints.
 */
final class HarnessContext
{
    public ?Quote $quote = null;

    /** @var array<int, array<string, mixed>> */
    public array $log = [];

    public function __construct(
        public readonly TestCase $test,
        public readonly User $staff,
        public readonly User $buyer,
    ) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public function record(string $action, array $meta = []): void
    {
        $this->log[] = ['action' => $action] + $meta;
    }
}
