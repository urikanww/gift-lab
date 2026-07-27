<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The manual-B2B closure for a cancelled, already-paid (or partially paid)
 * order: there is no gateway to refund through, so cancelling a PAID/PARTIAL
 * invoice mints one of these instead - the amount owed back to the buyer,
 * verbatim off the invoice (GST-inclusive, never recomputed). See
 * QuoteService::voidInvoiceAndCredit().
 *
 * @property int $id
 * @property int $quote_id
 * @property int $invoice_id
 * @property string $amount
 * @property string|null $reason
 * @property int|null $issued_by
 * @property \Illuminate\Support\Carbon|null $issued_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class CreditNote extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'quote_id',
        'invoice_id',
        'amount',
        'reason',
        'issued_by',
        'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'issued_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Quote, CreditNote>
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    /**
     * @return BelongsTo<Invoice, CreditNote>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<User, CreditNote>
     */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
