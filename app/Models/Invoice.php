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
 * @property string|null $invoice_ref
 * @property string|null $terms
 * @property PaymentState $payment_state
 * @property string $amount
 * @property string $currency
 * @property int|null $issued_by
 * @property \Illuminate\Support\Carbon|null $issued_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class Invoice extends Model
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
     * @return BelongsTo<Quote, Invoice>
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    /**
     * @return BelongsTo<User, Invoice>
     */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
