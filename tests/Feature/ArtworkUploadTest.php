<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;

// The public upload writes to the dedicated (private) artwork disk, not the
// default disk - fake exactly that so assertions target where files really land.
beforeEach(function (): void {
    $this->artworkDisk = (string) config('filesystems.artwork_disk');
    Storage::fake($this->artworkDisk);
    // Isolate the per-IP upload limiter between tests (array cache persists
    // within a process, so a prior test's hits would otherwise bleed through).
    RateLimiter::clear('artwork-uploads');
    RateLimiter::clear('artwork-preview');
});

it('stores an uploaded artwork image and returns a ref + preview url', function (): void {
    $response = $this->postJson('/api/uploads/artwork', [
        'artwork' => UploadedFile::fake()->image('logo.png', 400, 400),
    ]);

    $response->assertCreated()->assertJsonStructure(['ref', 'url']);
    Storage::disk($this->artworkDisk)->assertExists($response->json('ref'));
});

it('stores anon artwork on the private disk, never the public one', function (): void {
    Storage::fake('public');

    $ref = $this->postJson('/api/uploads/artwork', [
        'artwork' => UploadedFile::fake()->image('logo.png', 400, 400),
    ])->assertCreated()->json('ref');

    // Lands on the private artwork disk, and NOT on the world-readable public
    // disk (which is what the app default resolves to in prod, FILESYSTEM_DISK=s3).
    Storage::disk($this->artworkDisk)->assertExists($ref);
    Storage::disk('public')->assertMissing($ref);
    expect(config('filesystems.artwork_disk'))->not->toBe('public');
});

it('rejects a non-image upload', function (): void {
    $this->postJson('/api/uploads/artwork', [
        'artwork' => UploadedFile::fake()->create('malware.exe', 10, 'application/octet-stream'),
    ])->assertStatus(422);
});

it('rejects an SVG upload (stored-XSS exclusion)', function (): void {
    $this->postJson('/api/uploads/artwork', [
        'artwork' => UploadedFile::fake()->create('logo.svg', 4, 'image/svg+xml'),
    ])->assertStatus(422);
});

it('re-issues a temporary preview url for a stored artwork ref', function (): void {
    $ref = $this->postJson('/api/uploads/artwork', [
        'artwork' => UploadedFile::fake()->image('logo.png', 200, 200),
    ])->assertCreated()->json('ref');

    $res = $this->getJson('/api/uploads/artwork/preview?ref='.urlencode($ref))->assertOk();
    expect($res->json('url'))->toContain($ref);
});

it('refuses a preview for an unknown or out-of-namespace ref', function (): void {
    Storage::disk($this->artworkDisk)->put('artwork/exists.png', 'x');

    $this->getJson('/api/uploads/artwork/preview?ref=artwork/missing.png')->assertNotFound();
    $this->getJson('/api/uploads/artwork/preview?ref='.urlencode('secret.png'))->assertNotFound();
    $this->getJson('/api/uploads/artwork/preview?ref='.urlencode('artwork/../secret'))->assertNotFound();
});

it('throttles the public upload after the tightened per-minute limit', function (): void {
    // 10/min burst limit (AppServiceProvider 'artwork-uploads'): the 11th
    // request from the same IP inside the window must be rejected with 429.
    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/api/uploads/artwork', [
            'artwork' => UploadedFile::fake()->image("logo{$i}.png", 64, 64),
        ])->assertCreated();
    }

    $this->postJson('/api/uploads/artwork', [
        'artwork' => UploadedFile::fake()->image('over-limit.png', 64, 64),
    ])->assertStatus(429);
});

it('lets the read-only preview serve a realistic page burst the upload route would reject', function (): void {
    // Regression: the preview shared the upload's 10/min budget, so a single
    // order-detail render (one preview per customized line, x2 for the desktop
    // table + mobile list) exhausted it and every design silently vanished.
    Storage::disk($this->artworkDisk)->put('artwork/burst.png', 'x');
    $url = '/api/uploads/artwork/preview?ref='.urlencode('artwork/burst.png');

    // 40 back-to-back reads - well past the upload limit, well within a page
    // load for a large order - must all succeed.
    for ($i = 0; $i < 40; $i++) {
        $this->getJson($url)->assertOk();
    }

    // The upload route keeps its tight budget: the two limiters are independent,
    // so the burst above must not have spent any of the upload allowance.
    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/api/uploads/artwork', [
            'artwork' => UploadedFile::fake()->image("after-burst{$i}.png", 64, 64),
        ])->assertCreated();
    }
    $this->postJson('/api/uploads/artwork', [
        'artwork' => UploadedFile::fake()->image('upload-over-limit.png', 64, 64),
    ])->assertStatus(429);
});
