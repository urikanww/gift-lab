<?php

declare(strict_types=1);

use App\Http\Controllers\AdminCatalogueController;
use App\Http\Controllers\AdminProductController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BrandKitController;
use App\Http\Controllers\CatalogueController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LeadTimeEstimateController;
use App\Http\Controllers\PayNowController;
use App\Http\Controllers\PriceEstimateController;
use App\Http\Controllers\PricingConfigController;
use App\Http\Controllers\ProcurementController;
use App\Http\Controllers\ProductionQueueController;
use App\Http\Controllers\ProofController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\TrackingController;
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
// Self-serve buyer registration (spec 6.1 Stage 0 — account created at
// Request Quote). Throttled like login to blunt bulk account creation.
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:6,1');

// Public, no-account catalogue (browse + live estimate).
Route::middleware('throttle:60,1')->group(function (): void {
    Route::get('/catalogue', [CatalogueController::class, 'index']);
    // {key} = slug (canonical, user-friendly) or numeric id (legacy links).
    Route::get('/catalogue/{key}', [CatalogueController::class, 'show']);
    // 3D model stream for the interactive viewer (published MODEL_3D only).
    Route::get('/catalogue/{key}/model', [CatalogueController::class, 'model']);
    Route::post('/price-estimate', PriceEstimateController::class);
    // Deadline-aware delivery window (queue-depth aware, ranged/conservative).
    Route::post('/lead-time-estimate', LeadTimeEstimateController::class);
});

// Designer artwork upload (account-free designer, spec 6.1). Public + writes
// 10 MB files, so it's throttled far tighter than the read-mostly catalogue
// group above: a dedicated per-IP limiter with both a burst (per-minute) and a
// daily cap blunts anonymous storage/cost-DoS. Limiter defined in
// AppServiceProvider::boot ('artwork-uploads').
Route::post('/uploads/artwork', [UploadController::class, 'artwork'])
    ->middleware('throttle:artwork-uploads');

// Login-free order tracking — opaque code + email-prefix check. Throttled
// hard (anti-enumeration; the controller also returns a single generic error).
Route::post('/track', TrackingController::class)->middleware('throttle:10,1');

// Stripe webhook — unauthenticated, verified by signature (see controller).
Route::post('/stripe/webhook', [StripeWebhookController::class, 'handle'])->middleware('throttle:120,1');

Route::middleware(['auth:sanctum', 'throttle:120,1'])->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);

    // Per-company brand kit (saved logo + colours; scoped to own company).
    Route::get('/company/brand-kit', [BrandKitController::class, 'show']);
    Route::put('/company/brand-kit', [BrandKitController::class, 'update']);

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
    Route::post('/admin/products/{product}/verify-estimates', [AdminCatalogueController::class, 'verifyEstimates']);
    Route::post('/admin/products/{product}/model-file', [AdminCatalogueController::class, 'uploadModelFile']);
    Route::patch('/admin/settings/auto-publish', [AdminCatalogueController::class, 'setAutoPublish']);

    // CORE product/variant management (staff; audit E4) — ops add a blank or
    // fix stock/price without seeders or DB access.
    Route::get('/admin/products', [AdminProductController::class, 'index']);
    Route::post('/admin/products', [AdminProductController::class, 'store']);
    // Must be registered before the /{product} wildcard routes below, or
    // "bulk-publish" would be captured as a {product} id.
    Route::post('/admin/products/bulk-publish', [AdminProductController::class, 'bulkPublish']);
    // Detail/edit fetch — withTrashed so the editor can open an archived row.
    Route::get('/admin/products/{product}', [AdminProductController::class, 'show'])->withTrashed();
    Route::get('/admin/products/{product}/history', [AdminProductController::class, 'history'])->withTrashed();
    Route::patch('/admin/products/{product}', [AdminProductController::class, 'update']);
    Route::delete('/admin/products/{product}', [AdminProductController::class, 'destroy']);
    // Archived rows are soft-deleted, so bind withTrashed to resolve them.
    Route::post('/admin/products/{product}/restore', [AdminProductController::class, 'restore'])->withTrashed();
    Route::post('/admin/products/{product}/image', [AdminProductController::class, 'uploadImage']);
    Route::delete('/admin/products/{product}/image', [AdminProductController::class, 'removeImage']);
    Route::post('/admin/products/{product}/variants', [AdminProductController::class, 'storeVariant']);
    Route::patch('/admin/variants/{variant}', [AdminProductController::class, 'updateVariant']);

    // Pricing/config editor (superadmin-only; audit E1/D7/E2) — every quote-time
    // number is editable without a deploy, and every change is audit-logged.
    Route::get('/admin/pricing-configs', [PricingConfigController::class, 'index']);
    Route::patch('/admin/pricing-configs/{pricingConfig}', [PricingConfigController::class, 'update']);

    // Staff console overview (read-only aggregate snapshot).
    Route::get('/admin/dashboard', [DashboardController::class, 'index']);

    // Superadmin user management (stricter than isStaff() — superadmin-only).
    Route::get('/admin/companies', [AdminUserController::class, 'companies']);
    Route::get('/admin/users', [AdminUserController::class, 'index']);
    Route::post('/admin/users', [AdminUserController::class, 'store']);
    Route::get('/admin/users/{user}', [AdminUserController::class, 'show'])->withTrashed();
    Route::patch('/admin/users/{user}', [AdminUserController::class, 'update'])->withTrashed();
    Route::delete('/admin/users/{user}', [AdminUserController::class, 'deactivate']);
    Route::post('/admin/users/{user}/reactivate', [AdminUserController::class, 'reactivate'])->withTrashed();
    Route::post('/admin/users/{user}/password', [AdminUserController::class, 'resetPassword'])->withTrashed();
});
