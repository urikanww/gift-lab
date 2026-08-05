<?php

declare(strict_types=1);

use App\Http\Controllers\GoogleAuthController;
use Illuminate\Support\Facades\Route;

// The customer + staff UI is the decoupled SPA in /frontend. This backend is
// API-only; the root simply identifies the service.
Route::get('/', fn () => response()->json([
    'app' => 'Gift Lab API',
    'docs' => 'see docs/API.md',
]));

// Google OAuth redirect + callback. These are full-page browser navigations (not
// XHR), so they live on the `web` (session) group: Socialite stores its CSRF
// `state` in the session on redirect and validates it on callback, and the
// callback establishes the Sanctum SPA session before bouncing back to the SPA.
// Throttled like /login (per IP). Buyers only - see GoogleAuthController.
Route::middleware('throttle:login')->group(function (): void {
    Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])->name('google.redirect');
    Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('google.callback');
});
