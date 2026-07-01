<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $quote_id
 * @property string $po_ref
 * @property PaymentState $payment_state
 */
class PurchaseOrder extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'quote_id',
        'po_ref',
        'invoice_ref',
        'terms',
        'payment_state',
        'amount',
        'currency',
        'issued_by',
        'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'payment_state' => PaymentState::class,
            'amount' => 'decimal:2',
            'issued_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Quote, PurchaseOrder>
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    /**
     * @return BelongsTo<User, PurchaseOrder>
     */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
