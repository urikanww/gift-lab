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
use Illuminate\Support\Collection;
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
            'dashboard.metrics.v3',
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
            // Buyer sent a proof back for changes: staff must issue a revised
            // version. Keyed on the PROOF state so it catches both paths - the
            // accepted-price order that stays PROOFING and the slim order that
            // moves to CHANGES_REQUESTED - which a quote-state count would miss.
            'changesRequested' => Proof::query()->where('state', 'CHANGES_REQUESTED')->count(),
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
                // The displayed order identifier. quoteId stays as the join key.
                'quoteReference' => $j->quote?->reference,
                'track' => $j->track->value,
                'state' => $j->state->value,
                'readyAt' => $j->ready_at?->toIso8601String(),
            ])
            ->all();
    }

    /** @return array<int,array<string,mixed>> */
    public function activity(): array
    {
        $rows = AuditLog::query()
            ->with('user:id,name')
            ->latest()
            ->limit(self::ACTIVITY_LIMIT)
            ->get(['id', 'user_id', 'auditable_type', 'auditable_id', 'event', 'created_at']);

        $references = $this->quoteReferences($rows);

        return $rows
            ->map(function (AuditLog $a) use ($references): array {
                $type = class_basename($a->auditable_type);
                $reference = $a->auditable_type === Quote::class
                    ? ($references[$a->auditable_id] ?? null)
                    : null;

                return [
                    'id' => $a->id,
                    'actor' => $a->user?->name,
                    'event' => $a->event,
                    'auditableType' => $type,
                    'auditableId' => $a->auditable_id,
                    // Composed here, not client-side: only the server knows a Quote
                    // is called an "Order" to humans. The feed is a generic renderer
                    // over any audited type, so it gets one ready-to-print string
                    // rather than a growing table of per-type naming rules.
                    'auditableLabel' => $reference !== null
                        ? "Order {$reference}"
                        : "{$type} #{$a->auditable_id}",
                    'at' => $a->created_at?->toIso8601String(),
                ];
            })
            ->all();
    }

    /**
     * Resolve display references for the Quote rows of an activity slice in ONE
     * query, keyed by id. Deliberately not the `auditable` morph relation: that
     * would fan out to one query per distinct type (or per row), and this class
     * promises no N+1. Rows of other types resolve to nothing and keep the
     * generic "Type #id" shape.
     *
     * withTrashed() on purpose - Quote soft-deletes, and an append-only audit
     * row outlives its quote. A cancelled order is still that order to support,
     * so it keeps its reference instead of degrading to a bare number.
     *
     * @param  Collection<int,AuditLog>  $rows
     * @return array<int,string>
     */
    private function quoteReferences(Collection $rows): array
    {
        $ids = $rows
            ->where('auditable_type', Quote::class)
            ->pluck('auditable_id')
            ->unique()
            ->all();

        if ($ids === []) {
            return [];
        }

        return Quote::withTrashed()
            ->whereIn('id', $ids)
            ->pluck('reference', 'id')
            // A hard-deleted quote yields no row at all; a blank reference would
            // render "Order " with nothing after it. Both fall back to "Quote #id".
            ->filter(fn ($reference): bool => is_string($reference) && $reference !== '')
            ->all();
    }

    /** @return array<string,mixed> */
    public function valueBooked(): array
    {
        $amount = Quote::query()->whereIn('state', self::BOOKED_STATES)->sum('total');

        return ['currency' => 'SGD', 'amount' => (float) $amount];
    }

    /**
     * The quote rides along because atRisk() projects quote.reference; without
     * it this bounded slice would cost one query per row. production() reuses
     * this builder for a bare count(), where the eager-load is a no-op - the
     * relation is only resolved once rows are hydrated.
     */
    private function atRiskQuery(): Builder
    {
        return ProductionJob::query()
            ->with('quote')
            ->whereIn('state', ['READY', 'IN_PRODUCTION'])
            ->where('ready_at', '<', now()->subHours(self::AT_RISK_SLA_HOURS));
    }
}
