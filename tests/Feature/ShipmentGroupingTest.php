<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Proof;
use App\Models\Quote;
use App\Services\QueueService;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->queue = app(QueueService::class);
    // A UV-track (CORE) product and a 3D product, so the quote's lines fan out
    // into two production jobs (one UV job + one job for the 3D line).
    $this->uvProduct = Product::factory()->create(['class' => 'CORE', 'print_method' => 'UV']);
    $this->model3d = Product::factory()->create(['class' => 'MODEL_3D', 'print_method' => 'FDM']);
});

it('groups all of an order\'s jobs into a single shipment', function (): void {
    // A quote whose lines produce 2 jobs: one UV-track line (with an approved
    // proof) folds into a UV job, and one MODEL_3D line splits into its own job.
    $quote = Quote::factory()->create(['company_id' => $this->company->id, 'state' => 'PROCURING']);
    $uvLine = LineItem::factory()->ready()->create([
        'quote_id' => $quote->id,
        'product_id' => $this->uvProduct->id,
        'qty' => 10,
        'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/core.png'],
    ]);
    Proof::factory()->forLine($uvLine)->approved()->create();
    LineItem::factory()->ready()->create([
        'quote_id' => $quote->id,
        'product_id' => $this->model3d->id,
        'qty' => 2,
        'customization' => ['mode' => 'designer', 'print_file_ref' => 'artwork/decal.png'],
    ]);

    $jobs = app(\App\Services\QueueService::class)->buildJobsForQuote($quote->load('lineItems.product'));

    expect($jobs)->toHaveCount(2)
        ->and($jobs->pluck('shipment_id')->unique()->values())->toHaveCount(1)
        ->and($quote->fresh()->shipments()->count())->toBe(1);
});
