<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Proof;
use App\Models\Quote;
use App\Models\User;
use App\Services\QueueService;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

function ready3dJob(string $ref): App\Models\ProductionJob
{
    $company = Company::factory()->create();
    $model3d = Product::factory()->create(['class' => 'MODEL_3D', 'print_method' => 'FDM']);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'state' => 'PROCURING']);
    Proof::factory()->approved()->create(['quote_id' => $quote->id]);
    LineItem::factory()->ready()->create([
        'quote_id' => $quote->id,
        'product_id' => $model3d->id,
        'customization' => ['print_file_ref' => $ref],
    ]);

    return app(QueueService::class)->buildJobsForQuote($quote->load('lineItems.product'))->first();
}

it('advances a READY job to IN_PRODUCTION when its print file is downloaded', function (): void {
    $disk = (string) config('filesystems.artwork_disk');
    Storage::fake($disk);
    Storage::disk($disk)->put('artwork/decal.png', 'PNGBYTES');
    $job = ready3dJob('artwork/decal.png');
    expect($job->state->value)->toBe('READY');

    Sanctum::actingAs(User::factory()->staffAdmin()->create());
    $this->get("/api/production-jobs/{$job->id}/print-file")->assertOk();

    expect($job->fresh()->state->value)->toBe('IN_PRODUCTION');
});

it('does not change state when re-downloading a job already past READY', function (): void {
    $disk = (string) config('filesystems.artwork_disk');
    Storage::fake($disk);
    Storage::disk($disk)->put('artwork/decal.png', 'PNGBYTES');
    $job = ready3dJob('artwork/decal.png');
    app(QueueService::class)->advance($job, App\Enums\JobState::InProduction);
    app(QueueService::class)->advance($job->fresh(), App\Enums\JobState::Shipped, 'REF1');

    Sanctum::actingAs(User::factory()->staffAdmin()->create());
    $this->get("/api/production-jobs/{$job->id}/print-file")->assertOk();

    expect($job->fresh()->state->value)->toBe('SHIPPED');
});
