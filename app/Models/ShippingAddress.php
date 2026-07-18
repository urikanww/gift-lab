<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-quote ship-to address (Workstream B). One row per quote, staff-editable,
 * defaulted from the company's single free-text address. Holds the structured
 * fields a courier API needs (recipient, phone, structured lines).
 *
 * @property int $id
 * @property int $quote_id
 * @property string $recipient_name
 * @property string $phone
 * @property string|null $email
 * @property string $line1
 * @property string|null $line2
 * @property string|null $city
 * @property string|null $state
 * @property string $postal_code
 * @property string $country
 * @property string|null $notes
 */
class ShippingAddress extends Model
{
    protected $fillable = [
        'quote_id',
        'recipient_name',
        'phone',
        'email',
        'line1',
        'line2',
        'city',
        'state',
        'postal_code',
        'country',
        'notes',
    ];

    /**
     * @return BelongsTo<Quote, ShippingAddress>
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }
}
