<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReorderState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property ReorderState $state
 */
class SupplierReorder extends Model
{
    protected $fillable = [
        'variant_id',
        'filament_id',
        'sku',
        'qty',
        'state',
        'approved_by',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'state' => ReorderState::class,
        ];
    }

    /**
     * @return BelongsTo<Variant, SupplierReorder>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    /**
     * @return BelongsTo<Filament, SupplierReorder>
     */
    public function filament(): BelongsTo
    {
        return $this->belongsTo(Filament::class);
    }
}
