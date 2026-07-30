<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Carrier;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Groups production jobs into a courier shipment. In Stage 2a each job gets
 * its own shipment (1:1), so a shipment still books one consignment; Phase 2b
 * collapses this to one shipment per quote. Owns the six courier fields that
 * used to live on production_jobs; status derives from member jobs (no own
 * state machine).
 *
 * @property int $id
 * @property int $quote_id
 * @property string|null $consignment_ref
 * @property Carrier|null $carrier
 * @property string|null $label_url
 * @property string|null $last_courier_status
 * @property \Illuminate\Support\Carbon|null $last_courier_status_at
 * @property \Illuminate\Support\Carbon|null $delivered_at
 */
class Shipment extends Model
{
    use HasFactory;

    protected $fillable = [
        'quote_id',
        'consignment_ref',
        'carrier',
        'label_url',
        'last_courier_status',
        'last_courier_status_at',
        'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'carrier' => Carrier::class,
            'last_courier_status_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Quote, Shipment>
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    /**
     * @return HasMany<ProductionJob>
     */
    public function jobs(): HasMany
    {
        return $this->hasMany(ProductionJob::class);
    }
}
