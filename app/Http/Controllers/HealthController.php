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
            // The cache store itself is unreachable (e.g. the Redis cache
            // connection is down in prod) - Cache::remember would otherwise
            // throw before a single check runs. Fall back to an uncached
            // probe so a degraded cache layer still yields a clean boolean
            // 200/503 body instead of an uncaught 500. Note a pure DB outage
            // does NOT need this path: Cache::remember still succeeds via
            // Redis, and the closure below reports database:false on its own.
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
