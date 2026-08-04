<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Public deep health probe for an uptime monitor. Boolean-only body (no infra
 * detail leaked), 200 when all checks pass / 503 otherwise. Result is cached a
 * few seconds so an unauthenticated request flood can't hammer DB/Redis.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            $result = Cache::remember('health:probe', 5, fn (): array => $this->runChecks());
        } catch (Throwable) {
            // The cache store itself is unreachable (default store is
            // DB-backed, so a DB outage - the #1 thing this probe exists to
            // catch - would otherwise throw here before a single check runs).
            // Fall back to an uncached probe so a failed cache layer can
            // never turn into an uncaught 500 instead of a graceful 503.
            $result = $this->runChecks();
        }

        return response()->json($result, $result['ok'] ? 200 : 503);
    }

    /** @return array{ok: bool, checks: array<string, bool>} */
    private function runChecks(): array
    {
        $checks = [
            'database' => $this->probe(fn () => DB::connection()->getPdo()),
            'redis' => $this->probe(fn () => Redis::connection()->ping()),
            'queue' => $this->probe(fn () => Queue::connection()->size()),
        ];

        return [
            'ok' => ! in_array(false, $checks, true),
            'checks' => $checks,
        ];
    }

    /** Run one check; any throw reads as a failed (false) check, never bubbles. */
    private function probe(callable $check): bool
    {
        try {
            $check();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
