<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StockMovementReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One append-only entry in the stock ledger. Never updated or deleted; a bad
 * entry is fixed by a compensating movement. Writes go through StockLedger, not
 * directly, so the cached variants.stock_on_hand stays in sync.
 *
 * @property int $id
 * @property int $variant_id
 * @property int $delta
 * @property StockMovementReason $reason
 */
class StockMovement extends Model
{
    // Append-only ledger: created_at is set on insert, there is no updated_at.
    public const UPDATED_AT = null;

    protected $fillable = [
        'variant_id',
        'delta',
        'unit',
        'reason',
        'ref_type',
        'ref_id',
        'actor_id',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'delta' => 'integer',
            'reason' => StockMovementReason::class,
        ];
    }

    /**
     * @return BelongsTo<Variant, StockMovement>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    /**
     * @return BelongsTo<User, StockMovement>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
