<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One printable part of a multi-part MODEL_3D product (e.g. Groot's head, body,
 * arms, legs). Persisted per part so superadmins can view the complete set and
 * the floor can print each piece; the largest part (is_primary) also lives on
 * products.model_file_ref for backward compatibility with the single-mesh path.
 */
class ProductModelPart extends Model
{
    protected $fillable = [
        'product_id',
        'label',
        'file_ref',
        'triangle_count',
        'is_primary',
        'sort',
    ];

    protected $casts = [
        'triangle_count' => 'integer',
        'is_primary' => 'boolean',
        'sort' => 'integer',
    ];

    /**
     * @return BelongsTo<Product, ProductModelPart>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
