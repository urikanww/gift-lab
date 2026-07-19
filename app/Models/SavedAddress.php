<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A buyer's saved ship-to address (address book, max 3 per user). Structured
 * fields mirror ShippingAddress so checkout prefill and validation share one
 * shape. Orders NEVER reference this row - checkout copies the text into the
 * per-quote ShippingAddress, so editing/deleting here can't alter placed orders.
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $label
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
class SavedAddress extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'label',
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
     * @return BelongsTo<User, SavedAddress>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
