<?php

declare(strict_types=1);

use App\Http\Controllers\AdminCatalogueController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CatalogueController;
use App\Http\Controllers\PriceEstimateController;
use App\Http\Controllers\ProcurementController;
use App\Http\Controllers\ProductionQueueController;
use App\Http\Controllers\ProofController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\PayNowController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\UploadController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes
|--------------------------------------------------------------------------
| Public catalogue is account-free (spec 6.1). Everything past Request Quote
| requires a Sanctum-authenticated session. Real-time updates are pushed over
| Reverb channels (see routes/channels.php); clients never poll these routes.
|
| Rate limits (defence-in-depth, A04/A07): unauthenticated surfaces are
| throttled tighter than authenticated ones; login is throttled hardest to
| blunt credential stuffing.
*/

// Authentication (Sanctum stateful cookie).
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1');

// Public, no-account catalogue (browse + live estimate).
Route::middleware('throttle:60,1')->group(function (): void {
    Route::get('/catalogue', [CatalogueController::class, 'index']);
    Route::get('/catalogue/{product}', [CatalogueController::class, 'show']);
    Route::post('/price-estimate', PriceEstimateController::class);
    // Designer artwork upload (account-free designer, spec 6.1).
    Route::post('/uploads/artwork', [UploadController::class, 'artwork']);
});

// Stripe webhook — unauthenticated, verified by signature (see controller).
Route::post('/stripe/webhook', [StripeWebhookController::class, 'handle'])->middleware('throttle:120,1');

Route::middleware(['auth:sanctum', 'throttle:120,1'])->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);

    // Quotes
    Route::get('/quotes', [QuoteController::class, 'index']);
    Route::post('/quotes', [QuoteController::class, 'store']);
    Route::get('/quotes/{quote}', [QuoteController::class, 'show']);
    Route::patch('/quotes/{quote}/amend', [QuoteController::class, 'amend']);
    Route::post('/quotes/{quote}/send', [QuoteController::class, 'send']);
    Route::post('/quotes/{quote}/accept', [QuoteController::class, 'accept']);
    Route::post('/quotes/{quote}/purchase-order', [QuoteController::class, 'issuePurchaseOrder']);
    Route::post('/quotes/{quote}/procure', [QuoteController::class, 'procure']);
    Route::post('/quotes/{quote}/cancel', [QuoteController::class, 'cancel']);
    Route::post('/quotes/{quote}/pay', [PayNowController::class, 'pay']);

    // Proofs
    Route::post('/quotes/{quote}/proofs', [ProofController::class, 'store']);
    Route::post('/proofs/{proof}/decide', [ProofController::class, 'decide']);

    // Procurement reconfirmation
    Route::post('/line-items/{lineItem}/reconfirm', [ProcurementController::class, 'reconfirm']);

    // Shared production queue
    Route::get('/production-queue', [ProductionQueueController::class, 'index']);
    Route::post('/production-jobs/{job}/advance', [ProductionQueueController::class, 'advance']);

    // Admin catalogue gate (staff; auto-publish toggle is superadmin-only)
    Route::get('/admin/catalogue', [AdminCatalogueController::class, 'index']);
    Route::post('/admin/products/{product}/publish', [AdminCatalogueController::class, 'publish']);
    Route::post('/admin/products/{product}/unpublish', [AdminCatalogueController::class, 'unpublish']);
    Route::patch('/admin/settings/auto-publish', [AdminCatalogueController::class, 'setAutoPublish']);
});
