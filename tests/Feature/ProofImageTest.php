<?php

declare(strict_types=1);

use App\Models\Proof;
use App\Models\Quote;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

it('serves a proof image over a valid signed url', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('artwork/v1.png', 'PNGDATA');
    $quote = Quote::factory()->create();
    $proof = Proof::create([
        'quote_id' => $quote->id, 'version' => 1,
        'artwork_version_ref' => 'artwork/v1.png', 'state' => 'SENT',
    ]);

    $url = URL::temporarySignedRoute('proofs.image', now()->addDays(14), ['proof' => $proof->id]);
    $this->get($url)->assertOk();
});

it('rejects an unsigned proof image request', function (): void {
    $quote = Quote::factory()->create();
    $proof = Proof::create([
        'quote_id' => $quote->id, 'version' => 1,
        'artwork_version_ref' => 'artwork/v1.png', 'state' => 'SENT',
    ]);

    $this->get("/api/proofs/{$proof->id}/image")->assertStatus(403);
});

it('rejects an expired signed proof image url', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('artwork/v1.png', 'PNGDATA');
    $quote = Quote::factory()->create();
    $proof = Proof::create([
        'quote_id' => $quote->id, 'version' => 1,
        'artwork_version_ref' => 'artwork/v1.png', 'state' => 'SENT',
    ]);

    $url = URL::temporarySignedRoute('proofs.image', now()->subMinute(), ['proof' => $proof->id]);
    $this->get($url)->assertStatus(403);
});

it('returns 404 when the proof artwork file is missing on the disk', function (): void {
    Storage::fake('local');
    $quote = Quote::factory()->create();
    $proof = Proof::create([
        'quote_id' => $quote->id, 'version' => 1,
        'artwork_version_ref' => 'artwork/missing.png', 'state' => 'SENT',
    ]);

    $url = URL::temporarySignedRoute('proofs.image', now()->addDays(14), ['proof' => $proof->id]);
    $this->get($url)->assertStatus(404);
});
