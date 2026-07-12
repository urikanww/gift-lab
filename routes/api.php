<?php

declare(strict_types=1);

use App\Http\Controllers\AdminCatalogueController;
use App\Http\Controllers\AdminPriceBreakdownController;
use App\Http\Controllers\AdminProductController;
use App\Http\Controllers\AdminReorderController;
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
// Self-serve buyer registration (spec 6.1 Stage 0 - account created at
// Request Quote). Throttled like login to blunt bulk account creation.
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:6,1');

// Public, no-account catalogue (browse + live estimate).
Route::middleware('throttle:60,1')->group(function (): void {
    Route::get('/catalogue', [CatalogueController::class, 'index']);
    // {key} = slug (canonical, user-friendly) or numeric id (legacy links).
    Route::get('/catalogue/{key}', [CatalogueController::class, 'show']);
    // 3D model stream for the interactive viewer (published MODEL_3D only).
    Route::get('/catalogue/{key}/model', [CatalogueController::class, 'model']);
    // Relevance-ranked "you might also like" (same category + complements).
    Route::get('/catalogue/{key}/related', [CatalogueController::class, 'related']);
    // Staff-curated affiliate gift ideas feed (cached; IP-flagged rows excluded).
    Route::get('/gift-ideas', [\App\Http\Controllers\GiftIdeasController::class, 'index']);
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

// Re-issue a short-lived preview URL for a stored artwork ref (cart preview).
Route::get('/uploads/artwork/preview', [UploadController::class, 'artworkPreview'])
    ->middleware('throttle:artwork-uploads');

// Login-free order tracking - opaque code + email-prefix check. Throttled
// hard (anti-enumeration; the controller also returns a single generic error).
Route::post('/track', TrackingController::class)->middleware('throttle:10,1');

// Signed one-click tracker (bookmark/QR from the confirmation). The signature is
// the second factor, so no email is needed; throttled like /track.
Route::get('/track/view', [TrackingController::class, 'view'])
    ->middleware(['signed:relative', 'throttle:10,1'])
    ->name('track.view');

// Stripe webhook - unauthenticated, verified by signature (see controller).
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
    Route::post('/production-jobs/advance-batch', [ProductionQueueController::class, 'advanceBatch']);
    Route::post('/production-jobs/{job}/advance-next', [ProductionQueueController::class, 'advanceNext']);
    // Streams the job's print-ready file (3D UV decal or approved proof
    // artwork) off the private disk so the floor can print it. Staff-gated.
    Route::get('/production-jobs/{job}/print-file', [ProductionQueueController::class, 'printFile']);

    // Admin catalogue gate (staff; auto-publish toggle is superadmin-only)
    Route::get('/admin/catalogue', [AdminCatalogueController::class, 'index']);
    Route::post('/admin/products/{product}/publish', [AdminCatalogueController::class, 'publish']);
    Route::post('/admin/products/{product}/unpublish', [AdminCatalogueController::class, 'unpublish']);
    Route::post('/admin/products/{product}/verify-estimates', [AdminCatalogueController::class, 'verifyEstimates']);
    Route::post('/admin/products/{product}/model-file', [AdminCatalogueController::class, 'uploadModelFile']);
    Route::post('/admin/products/{product}/print-zone', [AdminCatalogueController::class, 'savePrintZone']);
    Route::get('/admin/products/{product}/model', [AdminCatalogueController::class, 'adminModel']);
    // Print-floor production file (H2S .3mf); falls back to the model file.
    Route::get('/admin/products/{product}/production-file', [AdminCatalogueController::class, 'productionFile']);
    // Multi-part 3D models: stream, attach and remove individual parts (staff).
    Route::get('/admin/products/{product}/parts/{part}/model', [AdminCatalogueController::class, 'partModel']);
    Route::post('/admin/products/{product}/parts', [AdminCatalogueController::class, 'uploadModelPart']);
    Route::post('/admin/products/{product}/parts/{part}/primary', [AdminCatalogueController::class, 'setPrimaryPart']);
    Route::delete('/admin/products/{product}/parts/{part}', [AdminCatalogueController::class, 'deleteModelPart']);
    // Download selected plates as a ZIP for the print floor's slicer.
    Route::post('/admin/products/{product}/parts/export', [AdminCatalogueController::class, 'exportParts']);
    // Re-pull the latest geometry/parts/dimensions from the model's source (staff).
    Route::post('/admin/products/{product}/pull-source', [AdminCatalogueController::class, 'pullFromSource']);
    Route::patch('/admin/settings/auto-publish', [AdminCatalogueController::class, 'setAutoPublish']);

    // CORE product/variant management (staff; audit E4) - ops add a blank or
    // fix stock/price without seeders or DB access.
    Route::get('/admin/products', [AdminProductController::class, 'index']);
    Route::post('/admin/products', [AdminProductController::class, 'store']);
    // Must be registered before the /{product} wildcard routes below, or
    // "bulk-publish" would be captured as a {product} id.
    Route::post('/admin/products/bulk-publish', [AdminProductController::class, 'bulkPublish']);
    Route::post('/admin/products/import', [AdminProductController::class, 'import']);
    // Detail/edit fetch - withTrashed so the editor can open an archived row.
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

    // Supplier reorder buy-list (staff): open reorder drafts raised by
    // below-threshold / backorder procurement, and marking them received
    // (which restocks the variant through the ledger).
    Route::get('/admin/supplier-reorders', [AdminReorderController::class, 'index']);
    Route::post('/admin/supplier-reorders/{reorder}/receive', [AdminReorderController::class, 'receive']);

    // Capture-on-browse: paste a product URL -> draft SCRAPED_UV blank in the gate.
    Route::post('/admin/blank-candidates/capture', [\App\Http\Controllers\AdminBlankCaptureController::class, 'store']);

    // Staff blank recommender (affiliate-powered discovery -> gate / gift-ideas).
    Route::get('/admin/blank-recommendations', [\App\Http\Controllers\AdminBlankRecommendationController::class, 'index']);
    Route::post('/admin/blank-recommendations/add', [\App\Http\Controllers\AdminBlankRecommendationController::class, 'add']);
    Route::post('/admin/blank-recommendations/feature', [\App\Http\Controllers\AdminBlankRecommendationController::class, 'feature']);
    Route::delete('/admin/blank-recommendations/feature/{feature}', [\App\Http\Controllers\AdminBlankRecommendationController::class, 'unfeature']);

    // Pricing/config editor (superadmin-only; audit E1/D7/E2) - every quote-time
    // number is editable without a deploy, and every change is audit-logged.
    Route::get('/admin/pricing-configs', [PricingConfigController::class, 'index']);
    Route::patch('/admin/pricing-configs/{pricingConfig}', [PricingConfigController::class, 'update']);
    // Staff "test a quote" full breakdown (exposes internal cost/margin).
    Route::post('/admin/price-breakdown', AdminPriceBreakdownController::class);

    // Staff console overview (read-only aggregate snapshot).
    Route::get('/admin/dashboard', [DashboardController::class, 'index']);

    // Superadmin user management (stricter than isStaff() - superadmin-only).
    Route::get('/admin/companies', [AdminUserController::class, 'companies']);
    Route::get('/admin/users', [AdminUserController::class, 'index']);
    Route::post('/admin/users', [AdminUserController::class, 'store']);
    Route::get('/admin/users/{user}', [AdminUserController::class, 'show'])->withTrashed();
    Route::patch('/admin/users/{user}', [AdminUserController::class, 'update'])->withTrashed();
    Route::delete('/admin/users/{user}', [AdminUserController::class, 'deactivate']);
    Route::post('/admin/users/{user}/reactivate', [AdminUserController::class, 'reactivate'])->withTrashed();
    Route::post('/admin/users/{user}/password', [AdminUserController::class, 'resetPassword'])->withTrashed();
});
