<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProofState;
use App\Exceptions\InvalidStateTransitionException;
use Database\Factories\ProofFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Exceptions\DomainRuleException;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Immutable-once-approved proof (spec 6.3). Approval is recorded with
 * who/what-version/when; an approved proof can never be mutated - a new version
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
    use SoftDeletes;

    protected $fillable = [
        'quote_id',
        'version',
        'artwork_version_ref',
        'state',
        'approved_by',
        'approved_at',
        'notes',
    ];

    /**
     * Storage prefixes an artwork ref may legitimately point at. Anything else
     * is treated as not-a-stored-file: `artwork_version_ref` is a free-form
     * string that predates in-app upload, so existing rows hold pasted URLs and
     * arbitrary text. Also the traversal guard - a ref containing `..` must
     * never reach a disk read.
     */
    private const STORED_REF_PREFIXES = ['artwork/', 'proofs/'];

    /** True when the ref points at a file on the artwork disk. */
    public function hasStoredArtwork(): bool
    {
        $ref = (string) $this->artwork_version_ref;

        if ($ref === '' || str_contains($ref, '..')) {
            return false;
        }

        foreach (self::STORED_REF_PREFIXES as $prefix) {
            if (str_starts_with($ref, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A link the client can open, whichever shape the ref takes: a short-lived
     * signed URL for an uploaded file, the value itself when staff pasted a
     * real http(s) URL, or null when it is neither. Keeping this decision on
     * the model means the UI does not have to guess.
     */
    public function artworkUrl(): ?string
    {
        if ($this->hasStoredArtwork()) {
            // In-app viewing link; short-lived, re-minted on each load.
            return $this->signedArtworkUrl(now()->addMinutes(30));
        }

        $ref = (string) $this->artwork_version_ref;

        return str_starts_with($ref, 'http://') || str_starts_with($ref, 'https://') ? $ref : null;
    }

    /**
     * A time-limited link to the stored artwork, or null when the ref isn't a
     * stored file.
     *
     * On an object store (DigitalOcean Spaces / any S3 disk) this is a PRESIGNED
     * URL straight to the bucket - reachable from anywhere (a buyer's email
     * client included) and independent of APP_URL. On a local/dev disk, which
     * cannot presign, it falls back to the signature-authenticated app route
     * (proofs.image) served off this host. That fallback is why proofs on a
     * `local` ARTWORK_DISK render as an APP_URL (e.g. localhost) link and break
     * in email: set ARTWORK_DISK to a Spaces disk so this presigns instead.
     */
    public function signedArtworkUrl(\DateTimeInterface $expiry): ?string
    {
        if (! $this->hasStoredArtwork()) {
            return null;
        }

        $disk = (string) config('filesystems.artwork_disk', 'local');
        $ref = (string) $this->artwork_version_ref;

        if (config("filesystems.disks.{$disk}.driver") === 's3') {
            return Storage::disk($disk)->temporaryUrl($ref, $expiry);
        }

        return URL::temporarySignedRoute('proofs.image', $expiry, ['proof' => $this->id]);
    }

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
                throw new DomainRuleException(
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
