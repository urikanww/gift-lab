<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $name
 * @property string $status
 */
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'registration_no',
        'billing_email',
        'phone',
        'address',
        'default_terms',
        'status',
        'created_by',
    ];

    /**
     * @return HasMany<User>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<Quote>
     */
    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    protected static function newFactory(): CompanyFactory
    {
        return CompanyFactory::new();
    }
}
