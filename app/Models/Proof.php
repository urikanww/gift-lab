<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProofState;
use App\Exceptions\InvalidStateTransitionException;
use Database\Factories\ProofFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Immutable-once-approved proof (spec 6.3). Approval is recorded with
 * who/what-version/when; an approved proof can never be mutated — a new version
 * is created instead. Guarded at the model layer via the saving() hook.
 *
 * @property int $id
 * @property int $quote_id
 * @property int $version
 * @property ProofState $state
 */
class Proof extends Model
{
    /** @use HasFactory<ProofFactory> */
    use HasFactory;

    protected $fillable = [
        'quote_id',
        'version',
        'artwork_version_ref',
        'state',
        'approved_by',
        'approved_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'state' => ProofState::class,
            'approved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Enforce immutability: once APPROVED, no further writes are allowed.
        static::updating(function (Proof $proof): void {
            $original = $proof->getOriginal('state');
            $wasApproved = $original instanceof ProofState
                ? $original === ProofState::Approved
                : $original === ProofState::Approved->value;

            if ($wasApproved) {
                throw new LogicException(
                    "Proof {$proof->id} is APPROVED and immutable; create a new version instead."
                );
            }
        });
    }

    /**
     * @return BelongsTo<Quote, Proof>
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    /**
     * @return BelongsTo<User, Proof>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function transitionTo(ProofState $target): void
    {
        if (! $this->state->canTransitionTo($target)) {
            throw InvalidStateTransitionException::between('proof', $this->state->value, $target->value);
        }

        $this->state = $target;
        $this->save();
    }

    protected static function newFactory(): ProofFactory
    {
        return ProofFactory::new();
    }
}
