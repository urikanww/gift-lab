<?php

declare(strict_types=1);

namespace Tests\Harness\Agents;

use App\Models\LineItem;
use App\Models\Quote;
use Laravel\Sanctum\Sanctum;
use Tests\Harness\Support\HarnessContext;

/**
 * Plays internal staff. Each method performs one real staff action over HTTP as
 * the staff_admin actor and asserts the transport succeeded (never a 500). It
 * does not assert workflow correctness — that is the ValidatorAgent's job.
 */
final class StaffAgent
{
    public function __construct(private readonly HarnessContext $ctx) {}

    private function actAsStaff(): void
    {
        Sanctum::actingAs($this->ctx->staff);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lineItems
     * @param  array<string, mixed>  $opts  merged into the payload (e.g. needed_by)
     * @return array<string, mixed>  the created quote resource
     */
    public function createDraft(int $companyId, array $lineItems, array $opts = []): array
    {
        $this->actAsStaff();

        $payload = ['company_id' => $companyId, 'line_items' => $lineItems] + $opts;
        $payload['shipping_address'] ??= [
            'recipient_name' => 'Rachel Tan',
            'phone' => '+6591234567',
            'line1' => '1 Marina Blvd',
            'postal_code' => '018989',
        ];

        $data = $this->ctx->test->postJson('/api/quotes', $payload)
            ->assertCreated()
            ->json('data');

        $this->ctx->quote = Quote::findOrFail($data['id']);
        $this->ctx->record('staff.createDraft', ['quote_id' => $data['id']]);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $opts  optional send payload (artwork omitted by default)
     */
    public function send(array $opts = []): void
    {
        $this->actAsStaff();
        $this->ctx->test->postJson("/api/quotes/{$this->ctx->quote->id}/send", $opts)->assertOk();
        $this->ctx->record('staff.send');
    }

    /**
     * Stage one line's artwork as a DRAFT proof. Returns the proof resource
     * (its `id` feeds BuyerAgent::approveProof / requestChanges).
     *
     * @return array<string, mixed>
     */
    public function stageProof(LineItem $line, string $ref): array
    {
        $this->actAsStaff();

        $data = $this->ctx->test
            ->postJson("/api/quotes/{$this->ctx->quote->id}/lines/{$line->id}/proofs", [
                'artwork_version_ref' => $ref,
            ])
            ->assertCreated()
            ->json('data');

        $this->ctx->record('staff.stageProof', ['line_id' => $line->id, 'proof_id' => $data['id']]);

        return $data;
    }

    public function sendProofs(): void
    {
        $this->actAsStaff();
        $this->ctx->test->postJson("/api/quotes/{$this->ctx->quote->id}/proofs/send")->assertOk();
        $this->ctx->record('staff.sendProofs');
    }

    /**
     * @return array<string, mixed>  the invoice payload
     */
    public function issueInvoice(string $poRef): array
    {
        $this->actAsStaff();

        $invoice = $this->ctx->test
            ->postJson("/api/quotes/{$this->ctx->quote->id}/invoice", ['po_ref' => $poRef])
            ->assertCreated()
            ->json('invoice');

        $this->ctx->record('staff.issueInvoice', ['po_ref' => $poRef]);

        return $invoice;
    }

    public function procure(): void
    {
        $this->actAsStaff();
        $this->ctx->test->postJson("/api/quotes/{$this->ctx->quote->id}/procure")->assertOk();
        $this->ctx->record('staff.procure');
    }

    /**
     * Confirm the goods are physically in hand. This is the gate that actually
     * releases a fully-resolved PROCURING quote to the floor (CONFIRMED ->
     * PROCURING -> READY no longer happens automatically inside procure()).
     */
    public function confirmStock(): void
    {
        $this->actAsStaff();
        $this->ctx->test->postJson("/api/quotes/{$this->ctx->quote->id}/confirm-stock")->assertOk();
        $this->ctx->record('staff.confirmStock');
    }

    public function cancel(?string $reason = 'harness cancel'): void
    {
        $this->actAsStaff();
        $this->ctx->test->postJson("/api/quotes/{$this->ctx->quote->id}/cancel", ['reason' => $reason])->assertOk();
        $this->ctx->record('staff.cancel', ['reason' => $reason]);
    }
}
