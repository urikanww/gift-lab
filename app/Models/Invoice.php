<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentState;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
 * @property string|null $amount_paid
 * @property string $gst_amount
 * @property string $gst_rate
 * @property string $currency
 * @property int|null $issued_by
 * @property \Illuminate\Support\Carbon|null $issued_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class Invoice extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'quote_id',
        'po_ref',
        'invoice_ref',
        'terms',
        'payment_state',
        'amount',
        'amount_paid',
        'gst_amount',
        'gst_rate',
        'currency',
        'issued_by',
        'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'payment_state' => PaymentState::class,
            'amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'gst_amount' => 'decimal:2',
            'gst_rate' => 'decimal:2',
            'issued_at' => 'datetime',
        ];
    }

    /**
     * How much has actually been collected against this invoice. PARTIAL/PAID
     * invoices carry an explicit amount_paid (staff record it at reconcile
     * time); a legacy PAID invoice with no recorded amount falls back to the
     * full invoice amount. Anything else (UNPAID/VOID with no amount_paid) has
     * collected nothing.
     */
    public function collectedAmount(): float
    {
        if ($this->amount_paid !== null) {
            return (float) $this->amount_paid;
        }

        return $this->payment_state === PaymentState::Paid ? (float) $this->amount : 0.0;
    }

    /**
     * Amount still owed = invoice total minus what has been collected. Never
     * negative (an over-payment reads as zero owed).
     */
    public function balanceOwed(): float
    {
        return max(0.0, (float) $this->amount - $this->collectedAmount());
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
