<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\VariantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $product_id
 * @property int $stock_on_hand
 * @property int $reorder_threshold
 */
class Variant extends Model
{
    /** @use HasFactory<VariantFactory> */
    use HasFactory;

    protected $fillable = [
        'product_id',
        'attributes',
        'sku',
        'stock_on_hand',
        'reorder_threshold',
        'price_delta',
        'currency',
    ];

    protected function casts(): array
    {
        return [
            'attributes' => 'array',
            'stock_on_hand' => 'integer',
            'reorder_threshold' => 'integer',
            'price_delta' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Product, Variant>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function isBelowThreshold(): bool
    {
        return $this->stock_on_hand <= $this->reorder_threshold;
    }

    public function hasStockFor(int $qty): bool
    {
        return $this->stock_on_hand >= $qty;
    }

    protected static function newFactory(): VariantFactory
    {
        return VariantFactory::new();
    }
}
