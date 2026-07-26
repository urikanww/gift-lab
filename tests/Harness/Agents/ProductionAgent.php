<?php

declare(strict_types=1);

namespace Tests\Harness\Agents;

use App\Models\LineItem;
use App\Models\ProductionJob;
use Laravel\Sanctum\Sanctum;
use Tests\Harness\Support\HarnessContext;

/**
 * Plays procurement + the production floor. Acts as the staff_admin actor (the
 * procurement/production routes are staff-gated). Job progress is driven through
 * the explicit `advance` action — downloading a print file no longer starts a
 * job in this app, so the harness never relies on that side effect.
 */
final class ProductionAgent
{
    public function __construct(private readonly HarnessContext $ctx) {}

    private function actAsStaff(): void
    {
        Sanctum::actingAs($this->ctx->staff);
    }

    /**
     * Resolve a line stuck in AWAITING_RECONFIRM.
     *
     * @param  'amend'|'approve'|'drop'  $action
     * @param  array<string, mixed>  $data  amend also needs {qty, unit_price}
     */
    public function reconfirm(LineItem $line, string $action, array $data = []): void
    {
        $this->actAsStaff();
        $this->ctx->test
            ->postJson("/api/line-items/{$line->id}/reconfirm", ['action' => $action] + $data)
            ->assertOk();
        $this->ctx->record('production.reconfirm', ['line_id' => $line->id, 'action' => $action]);
    }

    /**
     * @return array<int, array<string, mixed>>  queued job resources
     */
    public function pullQueue(): array
    {
        $this->actAsStaff();

        return $this->ctx->test->getJson('/api/production-queue')->assertOk()->json('data');
    }

    public function startJob(ProductionJob $job): void
    {
        $this->actAsStaff();
        $this->ctx->test
            ->postJson("/api/production-jobs/{$job->id}/advance", ['state' => 'IN_PRODUCTION'])
            ->assertOk();
        $this->ctx->record('production.startJob', ['job_id' => $job->id]);
    }

    public function ship(ProductionJob $job, string $consignmentRef = 'TRACK-1', string $carrier = 'NINJAVAN'): void
    {
        $this->actAsStaff();
        $this->ctx->test
            ->postJson("/api/production-jobs/{$job->id}/advance", [
                'state' => 'SHIPPED',
                'consignment_ref' => $consignmentRef,
                'carrier' => $carrier,
            ])
            ->assertOk();
        $this->ctx->record('production.ship', ['job_id' => $job->id]);
    }

    public function closeJob(ProductionJob $job): void
    {
        $this->actAsStaff();
        $this->ctx->test
            ->postJson("/api/production-jobs/{$job->id}/advance", ['state' => 'CLOSED'])
            ->assertOk();
        $this->ctx->record('production.closeJob', ['job_id' => $job->id]);
    }
}
