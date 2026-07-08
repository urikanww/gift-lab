<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\License;
use App\Enums\Model3dSource;
use App\Enums\PublishState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 3D model catalogue source (Phase 2 track - model present, API client stubbed).
 *
 * @property License $license
 * @property PublishState $publish_state
 */
class Model3D extends Model
{
    use SoftDeletes;

    protected $table = 'model3ds';

    protected $fillable = [
        'source',
        'source_id',
        'license',
        'creator_credit',
        'file_ref',
        'publish_state',
        'cannot_publish_reasons',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'source' => Model3dSource::class,
            'license' => License::class,
            'publish_state' => PublishState::class,
            'cannot_publish_reasons' => 'array',
        ];
    }

    /**
     * @return HasMany<Product>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'model3d_id');
    }
}
