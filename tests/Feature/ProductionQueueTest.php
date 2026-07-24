<?php

declare(strict_types=1);

use App\Enums\JobState;
use App\Enums\JobTrack;
use App\Events\ProductionQueueUpdated;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\ProductionJob;
use App\Models\Proof;
use App\Models\Quote;
use App\Models\User;
use App\Services\QueueService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->staff = User::factory()->staffAdmin()->create();
    $this->queue = app(QueueService::class);
    $this->product = Product::factory()->create(['class' => 'CORE', 'print_method' => 'UV']);
});

function readyQuoteWithProof(): Quote
{
    $quote = Quote::factory()->create(['company_id' => test()->company->id, 'state' => 'PROCURING']);
    $line = LineItem::factory()->ready()->create([
        'quote_id' => $quote->id,
        'product_id' => test()->product->id,
        'qty' => 10,
        'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/core.png'],
    ]);
    Proof::factory()->forLine($line)->approved()->create();

    return $quote->load('lineItems.product');
}

it('refuses to queue a quote without an approved proof (gate 1)', function (): void {
    $quote = Quote::factory()->create(['company_id' => $this->company->id, 'state' => 'PROCURING']);
    // An artwork line (needsProof) with no approved proof is a gate violation.
    LineItem::factory()->ready()->create([
        'quote_id' => $quote->id,
        'product_id' => $this->product->id,
        'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/core.png'],
    ]);

    expect(fn () => $this->queue->buildJobsForQuote($quote->load('lineItems.product')))
        ->toThrow(RuntimeException::class);
});

it('builds a job per track, sets ready_at, and moves the quote to READY', function (): void {
    Event::fake([ProductionQueueUpdated::class]);
    $quote = readyQuoteWithProof();

    $jobs = $this->queue->buildJobsForQuote($quote);

    expect($jobs)->toHaveCount(1)
        ->and($jobs->first()->track->value)->toBe('UV')
        ->and($jobs->first()->ready_at)->not->toBeNull()
        ->and($quote->fresh()->state->value)->toBe('READY');
    Event::assertDispatched(ProductionQueueUpdated::class);
});

it('prints the UV-flattened decal (print_file_ref) for a MODEL_3D job, not the proof mockup', function (): void {
    $model3d = Product::factory()->create(['class' => 'MODEL_3D', 'print_method' => 'FDM']);
    $quote = Quote::factory()->create(['company_id' => $this->company->id, 'state' => 'PROCURING']);
    $line = LineItem::factory()->ready()->create([
        'quote_id' => $quote->id,
        'product_id' => $model3d->id,
        'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/proof.png', 'print_file_ref' => 'artwork/decal-flat.png'],
    ]);
    $proof = Proof::factory()->forLine($line)->approved()->create();

    $job = $this->queue->buildJobsForQuote($quote->load('lineItems.product'))->first();
    $refs = collect($job->artwork_refs)->pluck('ref');

    expect($job->track->value)->toBe('3D')
        ->and($refs->all())->toContain('artwork/decal-flat.png')
        ->and($refs->all())->not->toContain($proof->artwork_version_ref);
});

it('falls back to the proof artwork for a legacy MODEL_3D line with no print_file_ref', function (): void {
    $model3d = Product::factory()->create(['class' => 'MODEL_3D', 'print_method' => 'FDM']);
    $quote = Quote::factory()->create(['company_id' => $this->company->id, 'state' => 'PROCURING']);
    $line = LineItem::factory()->ready()->create([
        'quote_id' => $quote->id,
        'product_id' => $model3d->id,
        'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/proof.png'],
    ]);
    $proof = Proof::factory()->forLine($line)->approved()->create();

    $job = $this->queue->buildJobsForQuote($quote->load('lineItems.product'))->first();

    expect(collect($job->artwork_refs)->pluck('ref')->all())->toContain($proof->artwork_version_ref);
});

it('splits multiple MODEL_3D lines into one job each, with its own decal, qty and print method', function (): void {
    $mugA = Product::factory()->create(['class' => 'MODEL_3D', 'print_method' => 'FDM']);
    $figB = Product::factory()->create(['class' => 'MODEL_3D', 'print_method' => 'RESIN']);
    $quote = Quote::factory()->create(['company_id' => $this->company->id, 'state' => 'PROCURING']);
    LineItem::factory()->ready()->create([
        'quote_id' => $quote->id, 'product_id' => $mugA->id, 'qty' => 4,
        'customization' => ['mode' => 'designer', 'print_file_ref' => 'artwork/decal-a.png'],
    ]);
    LineItem::factory()->ready()->create([
        'quote_id' => $quote->id, 'product_id' => $figB->id, 'qty' => 7,
        'customization' => ['mode' => 'designer', 'print_file_ref' => 'artwork/decal-b.png'],
    ]);

    $jobs = $this->queue->buildJobsForQuote($quote->load('lineItems.product'));
    // Each 3D job covers one line, so its artwork_refs holds a single entry.
    $byArtwork = $jobs->keyBy(fn ($j) => collect($j->artwork_refs)->pluck('ref')->first());

    expect($jobs)->toHaveCount(2)
        ->and($jobs->every(fn ($j): bool => $j->track->value === '3D'))->toBeTrue()
        ->and($byArtwork['artwork/decal-a.png']->qty)->toBe(4)
        ->and($byArtwork['artwork/decal-a.png']->print_method->value)->toBe('FDM')
        ->and($byArtwork['artwork/decal-b.png']->qty)->toBe(7)
        ->and($byArtwork['artwork/decal-b.png']->print_method->value)->toBe('RESIN');
});

it('keeps UV lines folded into one job while splitting the 3D line out', function (): void {
    $model3d = Product::factory()->create(['class' => 'MODEL_3D', 'print_method' => 'FDM']);
    $quote = Quote::factory()->create(['company_id' => $this->company->id, 'state' => 'PROCURING']);
    // Two UV lines (this test's CORE product), each with its own approved proof + one 3D line.
    $uv1 = LineItem::factory()->ready()->create([
        'quote_id' => $quote->id, 'product_id' => $this->product->id, 'qty' => 3,
        'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/uv-1.png'],
    ]);
    $uv2 = LineItem::factory()->ready()->create([
        'quote_id' => $quote->id, 'product_id' => $this->product->id, 'qty' => 5,
        'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/uv-2.png'],
    ]);
    $p1 = Proof::factory()->forLine($uv1)->approved()->create();
    $p2 = Proof::factory()->forLine($uv2)->approved()->create();
    LineItem::factory()->ready()->create([
        'quote_id' => $quote->id, 'product_id' => $model3d->id, 'qty' => 2,
        'customization' => ['mode' => 'designer', 'print_file_ref' => 'artwork/decal.png'],
    ]);

    $jobs = $this->queue->buildJobsForQuote($quote->load('lineItems.product'));
    $uv = $jobs->firstWhere('track', JobTrack::Uv);
    $threeD = $jobs->firstWhere('track', JobTrack::ThreeD);

    expect($jobs)->toHaveCount(2)
        ->and($uv->qty)->toBe(8)
        ->and(collect($uv->artwork_refs)->pluck('ref')->all())
        ->toContain($p1->artwork_version_ref)->toContain($p2->artwork_version_ref)
        ->and($threeD->qty)->toBe(2)
        ->and(collect($threeD->artwork_refs)->pluck('ref')->all())->toContain('artwork/decal.png');
});

it('uses the proof artwork for a UV job (print_file_ref never applies)', function (): void {
    $quote = readyQuoteWithProof();
    $proof = $quote->approvedProof();

    $job = $this->queue->buildJobsForQuote($quote)->first();

    expect($job->track->value)->toBe('UV')
        ->and(collect($job->artwork_refs)->pluck('ref')->all())->toContain($proof->artwork_version_ref);
});

it('orders the shared queue FCFS by readiness', function (): void {
    $late = readyQuoteWithProof();
    $this->queue->buildJobsForQuote($late);

    $early = readyQuoteWithProof();
    // Force the second quote's job to be ready earlier.
    $earlyJobs = $this->queue->buildJobsForQuote($early);
    $earlyJobs->first()->update(['ready_at' => now()->subHour()]);

    $ordered = $this->queue->queue();
    expect($ordered->first()->id)->toBe($earlyJobs->first()->id);
});

it('requires a consignment ref to mark a job shipped', function (): void {
    $job = $this->queue->buildJobsForQuote(readyQuoteWithProof())->first();
    $this->queue->advance($job, JobState::InProduction);

    Sanctum::actingAs($this->staff);
    $this->postJson("/api/production-jobs/{$job->id}/advance", ['state' => 'SHIPPED'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['consignment_ref']);

    expect($job->fresh()->state->value)->toBe('IN_PRODUCTION'); // unchanged
});

it('marks a job shipped with a consignment ref and audit-logs the transition', function (): void {
    $job = $this->queue->buildJobsForQuote(readyQuoteWithProof())->first();
    $this->queue->advance($job, JobState::InProduction);

    Sanctum::actingAs($this->staff);
    $this->postJson("/api/production-jobs/{$job->id}/advance", [
        'state' => 'SHIPPED',
        'consignment_ref' => 'SP123456789SG',
    ])
        ->assertOk()
        ->assertJsonPath('data.consignment_ref', 'SP123456789SG');

    expect($job->fresh()->consignment_ref)->toBe('SP123456789SG')
        ->and(
            AuditLog::where('event', 'production_job.advanced')
                ->whereJsonContains('new_values->state', 'SHIPPED')
                ->exists()
        )->toBeTrue();
});

function ready3dJobWithDecal(string $decalRef): ProductionJob
{
    $model3d = Product::factory()->create(['class' => 'MODEL_3D', 'print_method' => 'FDM']);
    $quote = Quote::factory()->create(['company_id' => test()->company->id, 'state' => 'PROCURING']);
    LineItem::factory()->ready()->create([
        'quote_id' => $quote->id,
        'product_id' => $model3d->id,
        'customization' => ['mode' => 'designer', 'print_file_ref' => $decalRef],
    ]);

    return test()->queue->buildJobsForQuote($quote->load('lineItems.product'))->first();
}

it('lets staff download a job print file off the private disk', function (): void {
    $disk = (string) config('filesystems.artwork_disk');
    Storage::fake($disk);
    Storage::disk($disk)->put('artwork/decal.png', 'PNGBYTES');
    $job = ready3dJobWithDecal('artwork/decal.png');

    Sanctum::actingAs($this->staff);
    $res = $this->get("/api/production-jobs/{$job->id}/print-file?ref=artwork/decal.png");

    $res->assertOk();
    expect($res->headers->get('content-disposition'))->toContain('decal.png')
        ->and($res->streamedContent())->toBe('PNGBYTES');
});

it('forbids a buyer from downloading a print file', function (): void {
    $disk = (string) config('filesystems.artwork_disk');
    Storage::fake($disk);
    Storage::disk($disk)->put('artwork/decal.png', 'PNGBYTES');
    $job = ready3dJobWithDecal('artwork/decal.png');
    $buyer = User::factory()->create(['company_id' => $this->company->id, 'role' => 'buyer']);

    Sanctum::actingAs($buyer);
    $this->get("/api/production-jobs/{$job->id}/print-file")->assertForbidden();
});

it('returns 404 when the print file is absent from the disk', function (): void {
    Storage::fake((string) config('filesystems.artwork_disk'));
    $job = ready3dJobWithDecal('artwork/gone.png'); // ref set, file never written

    Sanctum::actingAs($this->staff);
    $this->get("/api/production-jobs/{$job->id}/print-file?ref=artwork/gone.png")->assertNotFound();
});

it('returns 404 when the job artwork ref is not a valid artwork key', function (): void {
    Storage::fake((string) config('filesystems.artwork_disk'));
    // A UV job carries the proof ref (a proofs/ key here), which must fail the
    // artwork/ guard rather than reach a disk read.
    $job = $this->queue->buildJobsForQuote(readyQuoteWithProof())->first();
    $ref = collect($job->artwork_refs)->pluck('ref')->first();

    Sanctum::actingAs($this->staff);
    $this->get("/api/production-jobs/{$job->id}/print-file?ref={$ref}")->assertNotFound();
});

it('restricts the queue endpoint to staff', function (): void {
    $buyer = User::factory()->create(['company_id' => $this->company->id, 'role' => 'buyer']);

    Sanctum::actingAs($buyer);
    $this->getJson('/api/production-queue')->assertForbidden();

    Sanctum::actingAs($this->staff);
    $this->getJson('/api/production-queue')->assertOk();
});

it('exposes every model part + the customization/zone on a job line for the floor', function (): void {
    $zone = ['normal' => [0, 0, 1], 'center' => [0, 0, 5], 'up' => [0, 1, 0], 'width_mm' => 40, 'height_mm' => 30];
    $model3d = Product::factory()->create([
        'class' => 'MODEL_3D',
        'print_method' => 'FDM',
        'model_file_ref' => 'models3d/x-1.stl',
        'print_zone' => $zone,
    ]);
    $model3d->modelParts()->createMany([
        ['label' => 'Body', 'file_ref' => 'models3d/x-1.stl', 'triangle_count' => 300, 'is_primary' => true, 'sort' => 0],
        ['label' => 'Head', 'file_ref' => 'models3d/x-1-part1.stl', 'triangle_count' => 100, 'is_primary' => false, 'sort' => 1],
    ]);
    $quote = Quote::factory()->create(['company_id' => $this->company->id, 'state' => 'PROCURING']);
    LineItem::factory()->ready()->create([
        'quote_id' => $quote->id,
        'product_id' => $model3d->id,
        'customization' => ['mode' => 'designer', 'print_file_ref' => 'artwork/decal.png', 'filament_color' => 'Black', 'text' => 'HELLO'],
    ]);
    $this->queue->buildJobsForQuote($quote->load('lineItems.product'));

    Sanctum::actingAs($this->staff);
    $this->getJson('/api/production-queue')
        ->assertOk()
        ->assertJsonPath('data.0.line_items.0.product.id', $model3d->id)
        ->assertJsonPath('data.0.line_items.0.product.class', 'MODEL_3D')
        ->assertJsonPath('data.0.line_items.0.product.has_model', true)
        ->assertJsonPath('data.0.line_items.0.product.print_zone.width_mm', 40)
        ->assertJsonCount(2, 'data.0.line_items.0.product.model_parts')
        // Relation orders primary first.
        ->assertJsonPath('data.0.line_items.0.product.model_parts.0.is_primary', true)
        ->assertJsonPath('data.0.line_items.0.product.model_parts.0.label', 'Body')
        ->assertJsonPath('data.0.line_items.0.customization.filament_color', 'Black')
        ->assertJsonPath('data.0.line_items.0.customization.text', 'HELLO');
});

it('lets the floor download an individual model part, and forbids buyers', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('models3d/x-1-part1.stl', 'STLBYTES');
    $model3d = Product::factory()->create(['class' => 'MODEL_3D', 'print_method' => 'FDM']);
    $part = $model3d->modelParts()->create([
        'label' => 'Head',
        'file_ref' => 'models3d/x-1-part1.stl',
        'triangle_count' => 100,
        'is_primary' => false,
        'sort' => 0,
    ]);

    Sanctum::actingAs($this->staff);
    $res = $this->get("/api/admin/products/{$model3d->id}/parts/{$part->id}/model")->assertOk();
    expect($res->streamedContent())->toBe('STLBYTES');

    $buyer = User::factory()->create(['company_id' => $this->company->id, 'role' => 'buyer']);
    Sanctum::actingAs($buyer);
    $this->get("/api/admin/products/{$model3d->id}/parts/{$part->id}/model")->assertForbidden();
});
