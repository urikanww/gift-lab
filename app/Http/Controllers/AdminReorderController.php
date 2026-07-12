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
     * blanks/filament someone needs to actually buy.
     */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);

        $reorders = SupplierReorder::query()
            ->with(['variant.product', 'filament'])
            ->where('state', '!=', ReorderState::Received->value)
            ->latest()
            ->get()
            ->map(fn (SupplierReorder $r): array => $this->serialize($r));

        return response()->json(['data' => $reorders]);
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

        $this->audit->log($reorder, 'supplier_reorder.received', ['state' => $previous], [
            'state' => $reorder->state->value,
            'restocked_qty' => $reorder->variant !== null ? $qty : 0,
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
