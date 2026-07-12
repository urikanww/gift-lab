<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class GiftIdeaFeature extends Model
{
    /** @use HasFactory<\Database\Factories\GiftIdeaFeatureFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'source_product_id', 'name', 'image_url', 'offer_link', 'product_link',
        'price', 'currency', 'shop_name', 'ip_flagged', 'sort', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'ip_flagged' => 'boolean',
            'sort' => 'integer',
        ];
    }
}
