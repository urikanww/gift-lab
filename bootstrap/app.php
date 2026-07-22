<?php

use App\Exceptions\DomainRuleException;
use App\Exceptions\FeatureNotEnabledException;
use App\Exceptions\InvalidStateTransitionException;
use App\Exceptions\PaymentGatewayException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        // Granular per-action access gate for staff_admin (see EnsurePermission).
        $middleware->alias([
            'permission' => \App\Http\Middleware\EnsurePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Illegal state-machine transition = client acted on stale data. Surface
        // as 422 with friendly copy (never a 500), and log with context so the
        // race is diagnosable server-side without leaking internals to the user.
        $exceptions->render(function (InvalidStateTransitionException $e, Request $request): ?JsonResponse {
            if (! $request->expectsJson()) {
                return null;
            }

            Log::warning('Illegal state transition blocked.', [
                'user_id' => $request->user()?->id,
                'path' => $request->path(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'This item has already moved on, so that action is no longer available. Refresh and try again.',
            ], 422);
        });

        // Business-rule guard tripped (stale state / race): well-formed request,
        // illegal given current state. Surface the guard's own friendly message as
        // 422 (never a raw 500), logged at warning for diagnosis.
        $exceptions->render(function (DomainRuleException $e, Request $request): ?JsonResponse {
            if (! $request->expectsJson()) {
                return null;
            }

            Log::warning('Domain rule violation blocked.', [
                'user_id' => $request->user()?->id,
                'path' => $request->path(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        });

        // Feature-gated (deferred-scope) capability invoked: 409 Conflict, not a
        // 500. Logged at info - it's an expected guard, not a fault.
        $exceptions->render(function (FeatureNotEnabledException $e, Request $request): ?JsonResponse {
            if (! $request->expectsJson()) {
                return null;
            }

            Log::info('Feature-gated action attempted.', [
                'user_id' => $request->user()?->id,
                'path' => $request->path(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'This feature isn’t available yet. Please contact us if you need it enabled.',
            ], 409);
        });

        // Upstream payment provider failed. The gateway already logged the raw
        // provider error with context; here we only return safe copy + 502 so no
        // provider internals reach the client.
        $exceptions->render(function (PaymentGatewayException $e, Request $request): ?JsonResponse {
            if (! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'message' => 'We couldn’t reach the payment provider just now. No charge was made - please try again in a moment.',
            ], 502);
        });
    })->create();
