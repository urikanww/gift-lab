<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reverb is a soft dependency: a state change is committed to the DB first and
 * broadcasting is only a live-update convenience. If the Reverb server is down
 * (e.g. Pusher cURL error 7 "couldn't connect"), the transport throws while the
 * write has already persisted — letting it bubble would 500 an otherwise
 * successful request.
 *
 * dispatch() runs the event dispatch (which triggers the synchronous broadcast
 * on the `sync` queue) and swallows any transport-layer failure, logging it so
 * the outage is visible without ever failing the committed write.
 */
final class Broadcasting
{
    /**
     * Dispatch a broadcastable event, never letting a broadcast transport
     * failure escape. The callable performs the actual ::dispatch().
     */
    public static function dispatch(callable $dispatch): void
    {
        try {
            $dispatch();
        } catch (Throwable $e) {
            Log::warning('Broadcast dispatch failed; write already persisted.', [
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);
        }
    }
}
