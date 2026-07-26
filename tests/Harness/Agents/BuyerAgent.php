<?php

declare(strict_types=1);

namespace Tests\Harness\Agents;

use Laravel\Sanctum\Sanctum;
use Tests\Harness\Support\HarnessContext;

/**
 * Plays the customer. Scriptable disposition: approve, request changes, or go
 * silent. Acts as the owning-company buyer, which is who the proof-decision and
 * accept endpoints authorize.
 */
final class BuyerAgent
{
    public function __construct(private readonly HarnessContext $ctx) {}

    private function actAsBuyer(): void
    {
        Sanctum::actingAs($this->ctx->buyer);
    }

    public function accept(): void
    {
        $this->actAsBuyer();
        $this->ctx->test->postJson("/api/quotes/{$this->ctx->quote->id}/accept")->assertOk();
        $this->ctx->record('buyer.accept');
    }

    public function approveProof(int $proofId): void
    {
        $this->actAsBuyer();
        $this->ctx->test->postJson("/api/proofs/{$proofId}/decide", ['decision' => 'approve'])->assertOk();
        $this->ctx->record('buyer.approveProof', ['proof_id' => $proofId]);
    }

    public function requestChanges(int $proofId, string $notes): void
    {
        $this->actAsBuyer();
        $this->ctx->test->postJson("/api/proofs/{$proofId}/decide", [
            'decision' => 'request_changes',
            'notes' => $notes,
        ])->assertOk();
        $this->ctx->record('buyer.requestChanges', ['proof_id' => $proofId]);
    }

    /**
     * Models a non-responding buyer. Deliberately does nothing so the chase
     * mechanism has an un-actioned order to act on.
     */
    public function goSilent(): void
    {
        $this->ctx->record('buyer.goSilent');
    }
}
