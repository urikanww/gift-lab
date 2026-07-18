<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Models\AuditLog;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\ProductionJob;
use App\Models\Proof;
use App\Models\Quote;
use App\Models\SupplierReorder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Read-only aggregate metrics for the staff dashboard. Every method is a single
 * index-backed query (COUNT/GROUP BY/SUM) or a bounded, eager-loaded slice - no
 * row hydration for counting, no unbounded selects, no N+1.
 */
class DashboardMetrics
{
    /** Jobs waiting longer than this (hours) past their queue-entry time are at risk. */
    private const AT_RISK_SLA_HOURS = 72;

    private const ACTIVITY_LIMIT = 20;

    private const AT_RISK_LIMIT = 15;

    /** Quote states counted as commercially "booked". */
    private const BOOKED_STATES = [
        'ACCEPTED', 'PROOFING', 'PROOF_APPROVED', 'INVOICED', 'CONFIRMED', 'PROCURING', 'READY',
    ];

    /** @return array<string,mixed> */
    public function snapshot(bool $includeValue): array
    {
        // Counts are identical for staff and superadmin, so cache them under ONE
        // key (no staff/super split - that recomputed the same pipeline/production/
        // queues twice). Only valueBooked differs, so it gets its own key and is
        // computed/cached solely for superadmins.
        $counts = Cache::remember(
            'dashboard.metrics.v2',
            45,
            fn (): array => [
                'pipeline' => $this->pipeline(),
                'production' => $this->production(),
                'queues' => $this->queues(),
            ],
        );

        $valueBooked = $includeValue
            ? Cache::remember('dashboard.metrics.v1.value', 45, fn (): array => $this->valueBooked())
            : null;

        return [
            ...$counts,
            'valueBooked' => $valueBooked,
            'atRisk' => $this->atRisk(),
            'activity' => $this->activity(),
        ];
    }

    /** @return array<string,int> */
    public function pipeline(): array
    {
        return Quote::query()
            ->groupBy('state')
            ->selectRaw('state, COUNT(*) as c')
            ->pluck('c', 'state')
            ->map(fn ($c): int => (int) $c)
            ->all();
    }

    /** @return array<string,mixed> */
    public function production(): array
    {
        $byState = ProductionJob::query()
            ->groupBy('state')
            ->selectRaw('state, COUNT(*) as c')
            ->pluck('c', 'state')
            ->map(fn ($c): int => (int) $c)
            ->all();

        return [
            'byState' => $byState,
            'wip' => (int) ($byState['IN_PRODUCTION'] ?? 0),
            'overdue' => $this->atRiskQuery()->count(),
        ];
    }

    /** @return array<string,int> */
    public function queues(): array
    {
        return [
            'proofsPending' => Proof::query()->where('state', 'SENT')->count(),
            'procurementToReconfirm' => LineItem::query()->where('line_state', 'AWAITING_RECONFIRM')->count(),
            'cataloguePending' => Product::query()->where('publish_state', 'READY_TO_APPROVE')->count(),
            'reordersOpen' => SupplierReorder::query()->where('state', '!=', 'RECEIVED')->count(),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function atRisk(): array
    {
        return $this->atRiskQuery()
            ->orderBy('ready_at')
            ->limit(self::AT_RISK_LIMIT)
            ->get(['id', 'quote_id', 'track', 'state', 'ready_at'])
            ->map(fn (ProductionJob $j): array => [
                'jobId' => $j->id,
                'quoteId' => $j->quote_id,
                'track' => $j->track->value,
                'state' => $j->state->value,
                'readyAt' => $j->ready_at?->toIso8601String(),
            ])
            ->all();
    }

    /** @return array<int,array<string,mixed>> */
    public function activity(): array
    {
        return AuditLog::query()
            ->with('user:id,name')
            ->latest()
            ->limit(self::ACTIVITY_LIMIT)
            ->get(['id', 'user_id', 'auditable_type', 'auditable_id', 'event', 'created_at'])
            ->map(fn (AuditLog $a): array => [
                'id' => $a->id,
                'actor' => $a->user?->name,
                'event' => $a->event,
                'auditableType' => class_basename($a->auditable_type),
                'auditableId' => $a->auditable_id,
                'at' => $a->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /** @return array<string,mixed> */
    public function valueBooked(): array
    {
        $amount = Quote::query()->whereIn('state', self::BOOKED_STATES)->sum('total');

        return ['currency' => 'SGD', 'amount' => (float) $amount];
    }

    private function atRiskQuery(): Builder
    {
        return ProductionJob::query()
            ->whereIn('state', ['READY', 'IN_PRODUCTION'])
            ->where('ready_at', '<', now()->subHours(self::AT_RISK_SLA_HOURS));
    }
}
