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
