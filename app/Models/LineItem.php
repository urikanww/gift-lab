<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LineItemState;
use App\Exceptions\InvalidStateTransitionException;
use Database\Factories\LineItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $quote_id
 * @property int|null $job_id
 * @property int $product_id
 * @property int|null $variant_id
 * @property int $qty
 * @property LineItemState $line_state
 */
class LineItem extends Model
{
    /** @use HasFactory<LineItemFactory> */
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'quote_id',
        'job_id',
        'product_id',
        'variant_id',
        'qty',
        'unit_price',
        'currency',
        'customization',
        'line_state',
        'procured_qty',
        'procured_price',
        'procurement_note',
        'frozen_snapshot',
        'lead_time_days',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'unit_price' => 'decimal:2',
            'customization' => 'array',
            'line_state' => LineItemState::class,
            'procured_qty' => 'integer',
            'procured_price' => 'decimal:2',
            'frozen_snapshot' => 'array',
            'lead_time_days' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Quote, LineItem>
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    /**
     * @return BelongsTo<Product, LineItem>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Variant, LineItem>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    /**
     * @return BelongsTo<ProductionJob, LineItem>
     */
    public function job(): BelongsTo
    {
        return $this->belongsTo(ProductionJob::class, 'job_id');
    }

    public function transitionTo(LineItemState $target): void
    {
        if (! $this->line_state->canTransitionTo($target)) {
            throw InvalidStateTransitionException::between('line_item', $this->line_state->value, $target->value);
        }

        $this->line_state = $target;
        $this->save();
    }

    public function lineTotal(): string
    {
        return bcmul((string) $this->unit_price, (string) $this->qty, 2);
    }

    protected static function newFactory(): LineItemFactory
    {
        return LineItemFactory::new();
    }
}
