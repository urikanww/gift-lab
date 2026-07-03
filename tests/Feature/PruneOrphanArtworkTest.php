<?php

declare(strict_types=1);

use App\Models\LineItem;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->artworkDisk = (string) config('filesystems.artwork_disk');
    Storage::fake($this->artworkDisk);
});

/**
 * Write a file to the artwork disk and backdate its mtime so it clears the
 * prune grace window (the command keys eligibility off Storage::lastModified).
 */
function agedArtwork(string $disk, string $key, int $daysOld): void
{
    Storage::disk($disk)->put($key, 'PNGDATA');
    touch(Storage::disk($disk)->path($key), now()->subDays($daysOld)->getTimestamp());
}

it('deletes an old orphan but keeps a referenced upload', function (): void {
    $orphan = 'artwork/orphan.png';
    $referenced = 'artwork/referenced.png';

    agedArtwork($this->artworkDisk, $orphan, 30);
    agedArtwork($this->artworkDisk, $referenced, 30);

    // Point a real quote line at the "referenced" key so it must be kept
    // (the factory auto-provisions the parent quote/company/product).
    LineItem::factory()->create([
        'customization' => ['artwork_ref' => $referenced],
    ]);

    $this->artisan('artwork:prune-orphans', ['--days' => 7])->assertSuccessful();

    Storage::disk($this->artworkDisk)->assertMissing($orphan);
    Storage::disk($this->artworkDisk)->assertExists($referenced);
});

it('spares a recent unreferenced upload inside the grace window', function (): void {
    $fresh = 'artwork/just-uploaded.png';
    agedArtwork($this->artworkDisk, $fresh, 0); // uploaded today, ref not yet saved

    $this->artisan('artwork:prune-orphans', ['--days' => 7])->assertSuccessful();

    Storage::disk($this->artworkDisk)->assertExists($fresh);
});
