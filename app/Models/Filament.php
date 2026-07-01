<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 3D filament inventory (Phase 2 track). qty tracked in grams.
 *
 * @property string $material
 * @property string $color
 * @property string $qty_on_hand
 */
class Filament extends Model
{
    protected $fillable = [
        'material',
        'color',
        'qty_on_hand',
        'reorder_threshold',
    ];

    protected function casts(): array
    {
        return [
            'qty_on_hand' => 'decimal:3',
            'reorder_threshold' => 'decimal:3',
        ];
    }

    public function isBelowThreshold(): bool
    {
        return (float) $this->qty_on_hand <= (float) $this->reorder_threshold;
    }
}
