<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\JobState;
use App\Enums\JobTrack;
use App\Enums\PrintMethod;
use App\Exceptions\InvalidStateTransitionException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Floor unit of work. Stored in "production_jobs" (queue table owns "jobs").
 *
 * @property int $id
 * @property int $quote_id
 * @property JobTrack $track
 * @property JobState $state
 * @property \Illuminate\Support\Carbon|null $ready_at
 */
class ProductionJob extends Model
{
    use SoftDeletes;

    protected $table = 'production_jobs';

    protected $fillable = [
        'quote_id',
        'track',
        'ready_at',
        'state',
        'artwork_ref',
        'consignment_ref',
        'carrier',
        'print_method',
        'qty',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'track' => JobTrack::class,
            'ready_at' => 'datetime',
            'state' => JobState::class,
            'carrier' => \App\Enums\Carrier::class,
            'print_method' => PrintMethod::class,
            'qty' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Quote, ProductionJob>
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    /**
     * @return HasMany<LineItem>
     */
    public function lineItems(): HasMany
    {
        return $this->hasMany(LineItem::class, 'job_id');
    }

    public function transitionTo(JobState $target): void
    {
        if (! $this->state->canTransitionTo($target)) {
            throw InvalidStateTransitionException::between('production_job', $this->state->value, $target->value);
        }

        $this->state = $target;
        $this->save();
    }

    /**
     * Shared production queue read: FCFS by readiness (spec principle 2 / 6.6).
     * No customer-type priority. Jobs not yet ready are excluded.
     *
     * @param  Builder<ProductionJob>  $query
     * @return Builder<ProductionJob>
     */
    public function scopeQueueOrder(Builder $query): Builder
    {
        return $query
            ->whereNotNull('ready_at')
            ->whereIn('state', [JobState::Ready->value, JobState::InProduction->value])
            // Exclude jobs whose quote has been soft-deleted/cancelled - whereHas
            // respects the parent's SoftDeletes global scope - so a cancelled
            // order never lingers on the shared production floor queue.
            ->whereHas('quote')
            ->orderBy('ready_at');
    }
}
