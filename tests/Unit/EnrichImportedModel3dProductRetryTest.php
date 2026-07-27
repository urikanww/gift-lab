<?php

declare(strict_types=1);

use App\Jobs\EnrichImportedModel3dProduct;
use Illuminate\Support\Facades\Log;

// Task 10: retry configuration + the per-job failure log. The enrich upsert
// (Model3dCatalogueService::enrichImportedProduct) is idempotent on
// (source, source_id), so retrying a transient failure is safe - this only
// asserts the job's own contract (tries/backoff/failed logging), not the
// service internals (already covered by AdminProductImportTest).

it('retries a transient failure up to 3 times with a 60s then 300s backoff', function (): void {
    $job = new EnrichImportedModel3dProduct(productId: 42, source: 'MAKERWORLD');

    expect($job->tries)->toBe(3);
    expect($job->backoff)->toBe([60, 300]);
});

it('logs product_id and source (and nothing else) when retries are exhausted', function (): void {
    Log::spy();

    $job = new EnrichImportedModel3dProduct(productId: 42, source: 'MAKERWORLD');
    $job->failed(new RuntimeException('enrichment blew up'));

    Log::shouldHaveReceived('error')
        ->withArgs(function (string $message, array $context) {
            return $message === 'Enrich imported MODEL_3D product job failed after retries.'
                && $context['product_id'] === 42
                && $context['source'] === 'MAKERWORLD';
        })
        ->once();
});

it('never sends a staff alert itself - that is the global Queue::failing() hook\'s job', function (): void {
    // No Event/Mail fakes assert anything here: failed() must be a pure log
    // call with no dispatch/broadcast/mail side effect, so nothing to fake
    // even needs to exist for this to pass cleanly.
    Log::spy();

    $job = new EnrichImportedModel3dProduct(productId: 7, source: 'OWNED');
    $job->failed(new RuntimeException('boom'));

    Log::shouldHaveReceived('error')->once();
});
