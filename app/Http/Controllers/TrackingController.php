<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Quote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Login-free order tracking (spec: buyer follows an order with no account).
 * Identity = opaque tracking code + a first-5-of-email check. The code is the
 * anti-enumeration handle (sequential quote ids are guessable); the email
 * prefix is a light second factor. Route is rate-limited and every failure
 * returns the SAME generic error, so a caller can never learn whether a code
 * exists. Response carries status only - no pricing, no line detail, no PII.
 */
class TrackingController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tracking_code' => ['required', 'string', 'max:16'],
            'email' => ['required', 'string', 'max:255'],
        ]);

        $generic = fn (): JsonResponse => response()->json(
            ['message' => 'No order matches those details.'],
            404,
        );

        $code = strtoupper(trim($data['tracking_code']));

        $quote = Quote::query()
            ->with('company')
            ->where('tracking_code', $code)
            ->first();

        // Same generic failure for an unknown code and a wrong email prefix -
        // no signal about which part was wrong (anti-enumeration).
        if ($quote === null || $quote->company === null) {
            return $generic();
        }

        $expected = strtolower(substr((string) $quote->company->billing_email, 0, 5));
        $given = strtolower(substr(trim($data['email']), 0, 5));

        if ($expected === '' || ! hash_equals($expected, $given)) {
            return $generic();
        }

        return response()->json(app(\App\Services\OrderTracker::class)->payload($quote));
    }

    /**
     * Signed deep-link view. Route middleware (signed:relative) has already
     * proven authenticity, so we look the quote up by code and return the same
     * OrderTracker payload. Unknown code -> the same generic 404 as /track.
     */
    public function view(Request $request): JsonResponse
    {
        $code = strtoupper(trim((string) $request->query('code', '')));

        $quote = Quote::query()->where('tracking_code', $code)->first();

        if ($quote === null) {
            return response()->json(['message' => 'No order matches those details.'], 404);
        }

        return response()->json(app(\App\Services\OrderTracker::class)->payload($quote));
    }
}
