<?php

declare(strict_types=1);

use App\Models\LineItem;
use App\Models\Proof;
use App\Models\Quote;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * A proof always hangs off a customized line now (proofs.line_item_id is NOT
 * NULL), so every fixture seeds one line and binds the proof to it.
 */
function proofOnLine(Quote $quote, string $ref, string $state = 'SENT'): Proof
{
    $line = LineItem::factory()->create([
        'quote_id' => $quote->id,
        'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/seed.png'],
        'line_state' => 'PENDING',
    ]);

    return Proof::create([
        'quote_id' => $quote->id,
        'line_item_id' => $line->id,
        'version' => 1,
        'artwork_version_ref' => $ref,
        'state' => $state,
    ]);
}

it('serves a proof image over a valid signed url', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('artwork/v1.png', 'PNGDATA');
    $quote = Quote::factory()->create();
    $proof = proofOnLine($quote, 'artwork/v1.png');

    $url = URL::temporarySignedRoute('proofs.image', now()->addDays(14), ['proof' => $proof->id]);
    $this->get($url)->assertOk();
});

it('rejects an unsigned proof image request', function (): void {
    $quote = Quote::factory()->create();
    $proof = proofOnLine($quote, 'artwork/v1.png');

    $this->get("/api/proofs/{$proof->id}/image")->assertStatus(403);
});

it('rejects an expired signed proof image url', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('artwork/v1.png', 'PNGDATA');
    $quote = Quote::factory()->create();
    $proof = proofOnLine($quote, 'artwork/v1.png');

    $url = URL::temporarySignedRoute('proofs.image', now()->subMinute(), ['proof' => $proof->id]);
    $this->get($url)->assertStatus(403);
});

it('returns 404 when the proof artwork file is missing on the disk', function (): void {
    Storage::fake('local');
    $quote = Quote::factory()->create();
    $proof = proofOnLine($quote, 'artwork/missing.png');

    $url = URL::temporarySignedRoute('proofs.image', now()->addDays(14), ['proof' => $proof->id]);
    $this->get($url)->assertStatus(404);
});

it('rejects a proof image ref with a path traversal sequence', function (): void {
    Storage::fake('local');
    $quote = Quote::factory()->create();
    $proof = proofOnLine($quote, 'artwork/../../secrets.env');

    $url = URL::temporarySignedRoute('proofs.image', now()->addDays(14), ['proof' => $proof->id]);
    $this->get($url)->assertStatus(404);
});
