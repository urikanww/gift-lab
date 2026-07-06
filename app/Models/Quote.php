<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\JobState;
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

    /** Crockford-style base32 without ambiguous glyphs (I, L, O, U). */
    private const TRACKING_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** Ordered, buyer-facing tracking stages (CANCELLED handled separately). */
    public const TRACKING_STAGE_LABELS = [
        'REVIEW' => 'In review',
        'CONFIRMED' => 'Confirmed',
        'IN_PRODUCTION' => 'In production',
        'SHIPPED' => 'Shipped',
        'DELIVERED' => 'Delivered',
    ];

    protected $fillable = [
        'company_id',
        'tracking_code',
        'idempotency_key',
        'state',
        'currency',
        'subtotal',
        'delivery',
        'total',
        'price_snapshot_at',
        'amendment_log',
        'notes',
        'needed_by',
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
            'needed_by' => 'immutable_date',
        ];
    }

    protected static function booted(): void
    {
        // Assign an opaque tracking code before insert (unless one was set
        // explicitly, e.g. a backfill/import). Loops on the unlikely collision.
        static::creating(function (Quote $quote): void {
            if (empty($quote->tracking_code)) {
                do {
                    $code = self::generateTrackingCode();
                } while (self::withTrashed()->where('tracking_code', $code)->exists());

                $quote->tracking_code = $code;
            }
        });

        // Cascade soft-deletes to children. The FK cascadeOnDelete only fires on
        // a hard DELETE, so a soft-deleted quote would otherwise leave live line
        // items / proofs / jobs / POs pointing at a hidden parent (e.g. a
        // cancelled quote's job lingering on the shared production queue). On a
        // force-delete the DB-level cascade handles hard removal, so skip here.
        static::deleting(function (Quote $quote): void {
            if ($quote->isForceDeleting()) {
                return;
            }

            $quote->lineItems()->get()->each->delete();
            $quote->proofs()->get()->each->delete();
            $quote->jobs()->get()->each->delete();
            $quote->purchaseOrders()->get()->each->delete();
        });

        static::restoring(function (Quote $quote): void {
            $quote->lineItems()->onlyTrashed()->get()->each->restore();
            $quote->proofs()->onlyTrashed()->get()->each->restore();
            $quote->jobs()->onlyTrashed()->get()->each->restore();
            $quote->purchaseOrders()->onlyTrashed()->get()->each->restore();
        });
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

    public static function generateTrackingCode(): string
    {
        $out = 'GL-';
        for ($i = 0; $i < 6; $i++) {
            $out .= self::TRACKING_ALPHABET[random_int(0, 31)];
        }

        return $out;
    }

    /**
     * Coarse, buyer-facing stage for the public tracking page. Honest by
     * construction: on the floor (READY) we report the LEAST-progressed job, so
     * "Shipped" never shows while any part is still printing. Codes map to
     * labels in the tracking response.
     */
    public function trackingStage(): string
    {
        if ($this->state === QuoteState::Cancelled) {
            return 'CANCELLED';
        }

        if ($this->state === QuoteState::Closed) {
            return 'DELIVERED';
        }

        if ($this->state === QuoteState::Ready) {
            // pluck() returns JobState enum instances (the model casts `state`),
            // so compare enum-to-enum — comparing against ->value never matched
            // and left the tracker stuck on IN_PRODUCTION even once SHIPPED/CLOSED.
            $states = $this->jobs()->pluck('state');

            if ($states->isNotEmpty()) {
                // DELIVERED first: an all-CLOSED set also satisfies the
                // shipped-or-closed test below, so precedence matters.
                if ($states->every(fn (JobState $s): bool => $s === JobState::Closed)) {
                    return 'DELIVERED';
                }

                if ($states->every(fn (JobState $s): bool => $s === JobState::Shipped || $s === JobState::Closed)) {
                    return 'SHIPPED';
                }
            }

            return 'IN_PRODUCTION';
        }

        return match ($this->state) {
            QuoteState::ProofApproved, QuoteState::PoIssued,
            QuoteState::Confirmed, QuoteState::Procuring => 'CONFIRMED',
            default => 'REVIEW',
        };
    }

    /** Human label for the current tracking stage. */
    public function trackingStageLabel(): string
    {
        $stage = $this->trackingStage();

        return $stage === 'CANCELLED' ? 'Cancelled' : (self::TRACKING_STAGE_LABELS[$stage] ?? $stage);
    }

    protected static function newFactory(): QuoteFactory
    {
        return QuoteFactory::new();
    }
}
