<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StockMovementReason;
use App\Models\StockMovement;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The single choke point for changing a variant's stock. Every mutation is
 * recorded as an append-only movement and the cached variants.stock_on_hand is
 * updated in the same transaction, so the column always equals SUM(delta).
 *
 * Nothing else should write stock_on_hand directly.
 */
final class StockLedger
{
    /**
     * Record a stock movement and apply it to the cached on-hand count.
     *
     * @param  int  $delta  signed change (+in / -out)
     * @param  Model|null  $ref  originating record (order, adjustment, …)
     */
    public function record(
        Variant $variant,
        int $delta,
        StockMovementReason $reason,
        ?Model $ref = null,
        ?int $actorId = null,
        ?string $note = null,
    ): StockMovement {
        return DB::transaction(function () use ($variant, $delta, $reason, $ref, $actorId, $note): StockMovement {
            // Lock the row so two concurrent orders can't both read the same
            // on-hand and lose one another's decrement.
            $locked = Variant::query()->whereKey($variant->getKey())->lockForUpdate()->firstOrFail();

            $movement = StockMovement::create([
                'variant_id' => $locked->id,
                'delta' => $delta,
                'unit' => 'PCS',
                'reason' => $reason->value,
                'ref_type' => $ref?->getMorphClass(),
                'ref_id' => $ref?->getKey(),
                'actor_id' => $actorId ?? Auth::id(),
                'note' => $note,
            ]);

            $locked->stock_on_hand += $delta;
            $locked->save();

            // Keep the caller's instance consistent with what we just persisted.
            $variant->stock_on_hand = $locked->stock_on_hand;

            return $movement;
        });
    }
}
