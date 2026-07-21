<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Proof;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/**
 * Wave 2: staff upload proof artwork in the app instead of pasting a link from
 * some other service. The stored ref flows through the existing
 * artwork_version_ref string, so nothing downstream needs to know whether a
 * proof was uploaded or pasted.
 */
beforeEach(function (): void {
    Storage::fake(config('filesystems.artwork_disk'));
    $this->company = Company::factory()->create();
    $this->staff = User::factory()->staffAdmin()->create();
});

it('stores a staff proof upload and returns a ref plus a preview url', function (): void {
    Sanctum::actingAs($this->staff);

    $response = $this->postJson('/api/uploads/proof', [
        'proof' => UploadedFile::fake()->create('proof-v1.pdf', 200, 'application/pdf'),
    ])->assertCreated();

    $ref = $response->json('ref');
    expect($ref)->toStartWith('proofs/');
    expect($response->json('url'))->toBeString();

    // Kept apart from the public designer namespace so the two upload surfaces
    // stay distinguishable in storage.
    Storage::disk(config('filesystems.artwork_disk'))->assertExists($ref);
});

it('accepts an image proof as well as a PDF', function (): void {
    Sanctum::actingAs($this->staff);

    $this->postJson('/api/uploads/proof', [
        'proof' => UploadedFile::fake()->image('proof-v1.png'),
    ])->assertCreated();
});

it('rejects a proof over 3 MB', function (): void {
    Sanctum::actingAs($this->staff);

    // 3 MB exactly is the cap, so 3 MB + 1 KB is the first rejected size.
    $this->postJson('/api/uploads/proof', [
        'proof' => UploadedFile::fake()->create('huge.pdf', 3073, 'application/pdf'),
    ])->assertStatus(422)->assertJsonValidationErrors('proof');
});

it('rejects a file type that is neither an image nor a PDF', function (): void {
    Sanctum::actingAs($this->staff);

    $this->postJson('/api/uploads/proof', [
        'proof' => UploadedFile::fake()->create('payload.zip', 10, 'application/zip'),
    ])->assertStatus(422)->assertJsonValidationErrors('proof');
});

it('refuses a proof upload from a buyer', function (): void {
    $buyer = User::factory()->create(['company_id' => $this->company->id, 'role' => 'buyer']);
    Sanctum::actingAs($buyer);

    $this->postJson('/api/uploads/proof', [
        'proof' => UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf'),
    ])->assertForbidden();
});

it('refuses a proof upload from a guest', function (): void {
    $this->postJson('/api/uploads/proof', [
        'proof' => UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf'),
    ])->assertUnauthorized();
});

// The ref shape must not leak into the UI's decision-making: whether staff
// uploaded a file or pasted a link, the client gets one openable URL.
it('resolves an uploaded ref to a signed viewing url', function (): void {
    $quote = Quote::factory()->create(['company_id' => $this->company->id]);
    $proof = Proof::factory()->create([
        'quote_id' => $quote->id,
        'artwork_version_ref' => 'proofs/abc123.pdf',
    ]);

    expect($proof->hasStoredArtwork())->toBeTrue();
    expect($proof->artworkUrl())->toContain('/api/proofs/'.$proof->id.'/image');
});

it('passes a pasted http url straight through', function (): void {
    $quote = Quote::factory()->create(['company_id' => $this->company->id]);
    $proof = Proof::factory()->create([
        'quote_id' => $quote->id,
        'artwork_version_ref' => 'https://example.test/proof.pdf',
    ]);

    expect($proof->hasStoredArtwork())->toBeFalse();
    expect($proof->artworkUrl())->toBe('https://example.test/proof.pdf');
});

// Legacy rows hold arbitrary strings - an object-store key from before uploads
// existed, or free text. Those must resolve to null so the UI keeps showing the
// raw value rather than rendering a broken link.
it('returns no url for a ref that is neither stored nor a real link', function (): void {
    $quote = Quote::factory()->create(['company_id' => $this->company->id]);
    $proof = Proof::factory()->create([
        'quote_id' => $quote->id,
        'artwork_version_ref' => 'some-legacy-key',
    ]);

    expect($proof->artworkUrl())->toBeNull();
});

it('refuses to read a ref attempting path traversal', function (): void {
    $quote = Quote::factory()->create(['company_id' => $this->company->id]);
    $proof = Proof::factory()->create([
        'quote_id' => $quote->id,
        'artwork_version_ref' => 'proofs/../../.env',
    ]);

    expect($proof->hasStoredArtwork())->toBeFalse();
    expect($proof->artworkUrl())->toBeNull();
});
