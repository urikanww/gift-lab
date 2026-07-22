<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Proof;
use App\Models\Quote;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->buyer = User::factory()->create(['company_id' => $this->company->id, 'role' => 'buyer']);
    $this->quote = Quote::factory()->create([
        'company_id' => $this->company->id,
        'state' => 'PROOFING',
        'accepted_at' => now(),
    ]);
    $this->proof = Proof::factory()->create(['quote_id' => $this->quote->id, 'state' => 'SENT', 'version' => 1]);
});

it('stores buyer reference attachments on a change request and surfaces them', function (): void {
    Sanctum::actingAs($this->buyer);

    postJson("/api/proofs/{$this->proof->id}/decide", [
        'decision' => 'request_changes',
        'notes' => 'Match the reference colour.',
        'attachments' => ['artwork/ref-a.png', 'artwork/ref-b.jpg'],
    ])->assertOk()
        ->assertJsonPath('data.change_attachments.0.ref', 'artwork/ref-a.png')
        ->assertJsonPath('data.change_attachments.1.ref', 'artwork/ref-b.jpg');

    expect($this->proof->fresh()->change_refs)->toBe(['artwork/ref-a.png', 'artwork/ref-b.jpg']);

    // And they ride along on the order detail payload for staff to act on.
    $proofs = getJson("/api/quotes/{$this->quote->reference}")->assertOk()->json('data.proofs');
    expect($proofs[0]['change_attachments'])->toHaveCount(2);
});

it('accepts a change request with no attachments', function (): void {
    Sanctum::actingAs($this->buyer);

    postJson("/api/proofs/{$this->proof->id}/decide", [
        'decision' => 'request_changes',
        'notes' => 'Just make the logo bigger.',
    ])->assertOk()
        ->assertJsonPath('data.change_attachments', []);

    expect($this->proof->fresh()->change_refs)->toBeNull();
});

it('rejects an attachment ref outside the artwork namespace', function (): void {
    Sanctum::actingAs($this->buyer);

    postJson("/api/proofs/{$this->proof->id}/decide", [
        'decision' => 'request_changes',
        'notes' => 'Change this.',
        'attachments' => ['../../etc/passwd'],
    ])->assertStatus(422);
});
