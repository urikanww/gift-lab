<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\QuoteState;
use App\Exceptions\InvalidStateTransitionException;
use Database\Factories\QuoteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $company_id
 * @property QuoteState $state
 * @property string $subtotal
 * @property string $delivery
 * @property string $total
 */
class Quote extends Model
{
    /** @use HasFactory<QuoteFactory> */
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'state',
        'currency',
        'subtotal',
        'delivery',
        'total',
        'price_snapshot_at',
        'amendment_log',
        'notes',
        'created_by',
        'amended_by',
    ];

    protected function casts(): array
    {
        return [
            'state' => QuoteState::class,
            'subtotal' => 'decimal:2',
            'delivery' => 'decimal:2',
            'total' => 'decimal:2',
            'price_snapshot_at' => 'datetime',
            'amendment_log' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Company, Quote>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return HasMany<LineItem>
     */
    public function lineItems(): HasMany
    {
        return $this->hasMany(LineItem::class);
    }

    /**
     * @return HasMany<Proof>
     */
    public function proofs(): HasMany
    {
        return $this->hasMany(Proof::class);
    }

    /**
     * @return HasMany<ProductionJob>
     */
    public function jobs(): HasMany
    {
        return $this->hasMany(ProductionJob::class);
    }

    /**
     * @return HasMany<PurchaseOrder>
     */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    /**
     * Guarded state transition. Persists the new state or throws.
     */
    public function transitionTo(QuoteState $target): void
    {
        if (! $this->state->canTransitionTo($target)) {
            throw InvalidStateTransitionException::between('quote', $this->state->value, $target->value);
        }

        $this->state = $target;
        $this->save();
    }

    /**
     * Latest approved proof, if any — the source of the production print file.
     */
    public function approvedProof(): ?Proof
    {
        return $this->proofs()
            ->where('state', \App\Enums\ProofState::Approved->value)
            ->latest('version')
            ->first();
    }

    protected static function newFactory(): QuoteFactory
    {
        return QuoteFactory::new();
    }
}
