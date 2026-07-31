<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ReorderState;
use App\Enums\StockMovementReason;
use App\Models\SupplierReorder;
use App\Services\AuditLogger;
use App\Services\StockLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The buy-list: supplier reorder drafts raised when a variant drops below its
 * threshold or a backorder drives on-hand negative. Procurement drafts these
 * automatically; this surface lets staff see the open ones and mark them
 * received, which restocks the variant through the ledger.
 */
class AdminReorderController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly StockLedger $ledger,
    ) {}

    /**
     * Open reorders (everything not yet received), newest first. These are the
     * blanks/filament someone needs to actually buy. Paginated - the backlog is
     * auto-drafted per under-threshold variant and only clears on RECEIVED, so
     * it grows unbounded with an idle buy-list. Same per_page default/cap and
     * data+meta envelope as AdminProductController::history().
     */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);

        $query = SupplierReorder::query()
            ->with(['variant.product', 'filament'])
            // Free text: the reorder SKU, or the related product's name. Wildcards
            // escaped so a stray % or _ narrows literally (matches QuoteController).
            ->when($request->filled('q'), function ($q) use ($request): void {
                $raw = $request->input('q');
                if (! is_string($raw)) {
                    return;
                }
                $term = trim($raw);
                if ($term === '') {
                    return;
                }
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q->where(function ($w) use ($like): void {
                    $w->where('sku', 'like', $like)
                        ->orWhereHas('variant.product', fn ($p) => $p->where('name', 'like', $like));
                });
            })
            // Kind: a variant-backed blank vs a filament spool.
            ->when($request->query('kind') === 'variant', fn ($q) => $q->whereNotNull('variant_id'))
            ->when($request->query('kind') === 'filament', fn ($q) => $q->whereNotNull('filament_id'))
            // State: an explicit allowlist overrides the default open-only view (so
            // RECEIVED becomes selectable). Unknown values are dropped; if nothing
            // valid remains, fall back to the default != RECEIVED constraint rather
            // than silently widening to every state.
            ->when($request->filled('state'), function ($q) use ($request): void {
                $states = array_values(array_intersect(
                    array_map('strtoupper', array_filter(explode(',', (string) $request->query('state')))),
                    array_map(fn (ReorderState $s): string => $s->value, ReorderState::cases()),
                ));
                if ($states === []) {
                    $q->where('state', '!=', ReorderState::Received->value);

                    return;
                }
                $q->whereIn('state', $states);
            }, fn ($q) => $q->where('state', '!=', ReorderState::Received->value))
            // Negative on-hand only: the backorder deficit driving a reorder. Only
            // variant-backed rows carry an on-hand count, so filament rows drop out.
            ->when($request->query('negative_only') === '1', fn ($q) => $q->whereHas(
                'variant',
                fn ($w) => $w->where('stock_on_hand', '<', 0),
            ))
            ->when($request->filled('created_from'), fn ($q) => $q->whereDate('created_at', '>=', (string) $request->query('created_from')))
            ->when($request->filled('created_to'), fn ($q) => $q->whereDate('created_at', '<=', (string) $request->query('created_to')));

        // Sort — a small allowlist, default newest-first (created desc).
        $query = match ((string) $request->query('sort', 'newest')) {
            'oldest' => $query->oldest(),
            default => $query->latest(),
        };

        $paginator = $query->paginate(max(1, min((int) $request->integer('per_page', 20), 100)));

        return response()->json([
            'data' => collect($paginator->items())->map(fn (SupplierReorder $r): array => $this->serialize($r)),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Mark a reorder received: transition to RECEIVED and, for a variant-backed
     * reorder, add the quantity back to on-hand as a RESTOCK movement (pulling a
     * negative backorder balance toward zero). Filament reorders have no unit
     * ledger yet, so they only flip state.
     */
    public function receive(Request $request, SupplierReorder $reorder): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);

        if ($reorder->state === ReorderState::Received) {
            return response()->json(['message' => 'This reorder is already received.'], 422);
        }

        $previous = $reorder->state->value;
        $reorder->state = ReorderState::Received;
        $reorder->save();

        $qty = (int) round((float) $reorder->qty);
        if ($reorder->variant !== null && $qty > 0) {
            $this->ledger->record(
                $reorder->variant,
                $qty,
                StockMovementReason::Restock,
                $reorder,
                actorId: $request->user()->id,
                note: 'supplier reorder received',
            );
        }

        // Filament reorders have no ledger (filament is a bare counter); add the
        // received grams straight back so a filament reorder marked received
        // actually replenishes stock instead of only flipping its state.
        $filamentGrams = (float) $reorder->qty;
        if ($reorder->filament !== null && $filamentGrams > 0) {
            $reorder->filament->qty_on_hand = (float) $reorder->filament->qty_on_hand + $filamentGrams;
            $reorder->filament->save();
        }

        $this->audit->log($reorder, 'supplier_reorder.received', ['state' => $previous], [
            'state' => $reorder->state->value,
            'restocked_qty' => $reorder->variant !== null ? $qty : 0,
            'restocked_grams' => $reorder->filament !== null ? $filamentGrams : 0,
        ]);

        return response()->json(['data' => $this->serialize($reorder->fresh(['variant.product', 'filament']))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(SupplierReorder $reorder): array
    {
        $variant = $reorder->variant;
        $filament = $reorder->filament;

        return [
            'id' => $reorder->id,
            'state' => $reorder->state->value,
            'qty' => (float) $reorder->qty,
            'sku' => $reorder->sku,
            // A reorder is either a CORE/UV variant blank or a 3D filament spool.
            'kind' => $variant !== null ? 'variant' : 'filament',
            'item' => $variant !== null
                ? ($variant->product?->name ?? 'Product')
                : trim(($filament->material ?? '').' · '.($filament->color ?? '')),
            'variant_id' => $variant?->id,
            'product_id' => $variant?->product?->id,
            // Negative on-hand is the backorder deficit driving this reorder.
            'stock_on_hand' => $variant?->stock_on_hand,
            // Affiliate source to actually buy the blank from (UV/scraped).
            'source_url' => $variant?->product?->source_url,
            // All ranked buy links for this blank (local primary + marketplace
            // backups). source_url above stays the derived primary for callers
            // that only want one. Prices are indicative - re-check before buying.
            'source_links' => $variant?->product?->source_links ?? [],
            'created_at' => $reorder->created_at?->toIso8601String(),
        ];
    }
}
