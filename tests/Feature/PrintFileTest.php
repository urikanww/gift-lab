<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\ProductionJob;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

it('streams a single file of a job by ref', function (): void {
    Storage::fake(config('filesystems.artwork_disk'));
    Storage::disk(config('filesystems.artwork_disk'))->put('artwork/cap.png', 'PNGDATA');
    $staff = User::factory()->staffAdmin()->create();
    $quote = Quote::factory()->create(['state' => 'READY']);
    $job = ProductionJob::factory()->create([
        'quote_id' => $quote->id, 'track' => 'UV', 'state' => 'READY',
        'artwork_refs' => [['line_item_id' => 1, 'product_name' => 'Cap', 'ref' => 'artwork/cap.png']],
    ]);

    $this->actingAs($staff)->get("/api/production-jobs/{$job->id}/print-file?ref=artwork/cap.png")->assertOk();
});

it('404s a ref that is not on the job', function (): void {
    $staff = User::factory()->staffAdmin()->create();
    $job = ProductionJob::factory()->create(['track' => 'UV', 'state' => 'READY', 'artwork_refs' => [['line_item_id' => 1, 'product_name' => 'Cap', 'ref' => 'artwork/cap.png']]]);

    $this->actingAs($staff)->get("/api/production-jobs/{$job->id}/print-file?ref=artwork/evil.png")->assertNotFound();
});

it('streams a zip of every file on the job, each named product-basename', function (): void {
    $disk = (string) config('filesystems.artwork_disk');
    Storage::fake($disk);
    Storage::disk($disk)->put('artwork/cap.png', 'CAPDATA');
    Storage::disk($disk)->put('artwork/mug.png', 'MUGDATA');
    $staff = User::factory()->staffAdmin()->create();
    $job = ProductionJob::factory()->create([
        'track' => 'UV', 'state' => 'READY',
        'artwork_refs' => [
            ['line_item_id' => 1, 'product_name' => 'Cap', 'ref' => 'artwork/cap.png'],
            ['line_item_id' => 2, 'product_name' => 'Mug', 'ref' => 'artwork/mug.png'],
        ],
    ]);

    $res = $this->actingAs($staff)->get("/api/production-jobs/{$job->id}/print-files.zip");

    $res->assertOk();
    expect($res->headers->get('content-type'))->toContain('zip');

    // The streamed body is a real ZIP holding both files under labelled names.
    $tmp = tempnam(sys_get_temp_dir(), 'pf').'.zip';
    file_put_contents($tmp, $res->streamedContent());
    $zip = new ZipArchive;
    expect($zip->open($tmp))->toBeTrue();
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }
    $zip->close();
    @unlink($tmp);

    expect($names)->toContain('Cap-cap.png')->toContain('Mug-mug.png');
});

it('forbids a buyer from downloading the zip', function (): void {
    $company = Company::factory()->create();
    $buyer = User::factory()->create(['company_id' => $company->id, 'role' => 'buyer']);
    $job = ProductionJob::factory()->create(['track' => 'UV', 'state' => 'READY', 'artwork_refs' => [['line_item_id' => 1, 'product_name' => 'Cap', 'ref' => 'artwork/cap.png']]]);

    $this->actingAs($buyer)->get("/api/production-jobs/{$job->id}/print-files.zip")->assertForbidden();
});
