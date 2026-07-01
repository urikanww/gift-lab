<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// The customer + staff UI is the decoupled SPA in /frontend. This backend is
// API-only; the root simply identifies the service.
Route::get('/', fn () => response()->json([
    'app' => 'Gift Lab API',
    'docs' => 'see docs/API.md',
]));
