<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\License;
use App\Enums\PrintMethod;
use App\Enums\ProductClass;
use App\Enums\PublishState;
use App\Enums\StockMode;
use App\Services\Catalogue\CategoryClassifier;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property ProductClass $class
 * @property string|null $category
 * @property PublishState $publish_state
 * @property PrintMethod|null $print_method
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'class',
        'category',
        'base_cost',
        'price_override',
        'min_order_qty',
        'currency',
        'dimensions',
        'weight',
        'print_method',
        'publish_state',
        'cannot_publish_reasons',
        'stock_mode',
        'allow_backorder',
        'image_url',
        'source_url',
        'source_kind',
        'source_links',
        'source_product_id',
        'stock_estimate',
        'is_printable',
        'model3d_id',
        'license',
        'creator_credit',
        'model_file_ref',
        'production_file_ref',
        'ip_flagged',
        'ip_flag_reason',
        'decor_glb_ref',
        'print_zone',
        'filament_material',
        'filament_color',
        'est_grams',
        'est_print_minutes',
        'estimates_verified',
        'model_preview_verified',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'class' => ProductClass::class,
            'base_cost' => 'decimal:2',
            'price_override' => 'decimal:2',
            'min_order_qty' => 'integer',
            'dimensions' => 'array',
            'source_links' => 'array',
            'print_zone' => 'array',
            'weight' => 'decimal:3',
            'print_method' => PrintMethod::class,
            'publish_state' => PublishState::class,
            'cannot_publish_reasons' => 'array',
            'stock_mode' => StockMode::class,
            'allow_backorder' => 'boolean',
            'stock_estimate' => 'integer',
            'is_printable' => 'boolean',
            'license' => License::class,
            'est_grams' => 'decimal:3',
            'est_print_minutes' => 'decimal:1',
            'estimates_verified' => 'boolean',
            'model_preview_verified' => 'boolean',
            'ip_flagged' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Public URL slug: generated once from the name, then stable across
        // renames so shared/bookmarked links never break. Collisions get the
        // shortest unique numeric suffix.
        static::saving(function (Product $product): void {
            if ($product->slug !== null && $product->slug !== '') {
                return;
            }

            $base = Str::slug((string) $product->name) ?: 'product';
            $slug = $base;
            $i = 2;

            while (static::withTrashed()->where('slug', $slug)->when(
                $product->id !== null,
                fn ($q) => $q->whereKeyNot($product->id),
            )->exists()) {
                $slug = "{$base}-{$i}";
                $i++;
            }

            $product->slug = $slug;
        });

        // Marketplace category: assigned once from the name when absent, kept
        // when set explicitly (admin/seed overrides win over the classifier).
        static::saving(function (Product $product): void {
            if ($product->category === null || $product->category === '') {
                $product->category = app(CategoryClassifier::class)
                    ->classify((string) $product->name, $product->class);
            }
        });

        // Keep source_url as the derived primary buy link: first local link,
        // else the first link. Only when source_links is populated, so legacy
        // rows (MODEL_3D / manual) that set source_url directly are untouched.
        static::saving(function (Product $product): void {
            $links = $product->source_links;
            if (is_array($links) && $links !== []) {
                $product->source_url = \App\Support\SourceLinks::primaryUrl($links);
            }
        });

        // Keep source_kind in sync with the (possibly just-derived) source_url so
        // the catalogue gate can filter/display by provenance without URL parsing.
        static::saving(function (Product $product): void {
            $product->source_kind = \App\Support\SourceKind::fromUrl($product->source_url);
        });

        // Cascade soft-deletes to variants (FK cascadeOnDelete only fires on a
        // hard DELETE), so a soft-deleted product doesn't leave live variants.
        static::deleting(function (Product $product): void {
            if ($product->isForceDeleting()) {
                return;
            }

            $product->variants()->get()->each->delete();
        });

        static::restoring(function (Product $product): void {
            $product->variants()->onlyTrashed()->get()->each->restore();
        });
    }

    /**
     * Worst-case blank cost = the highest price across source_links, used to
     * quote B2C off the priciest source so per-order retail drift can't turn a
     * job unprofitable. Null when no link carries a price.
     */
    public function worstCaseBlankCost(): ?float
    {
        $prices = [];
        foreach ((array) $this->source_links as $link) {
            if (isset($link['price']) && is_numeric($link['price'])) {
                $prices[] = (float) $link['price'];
            }
        }

        return $prices === [] ? null : max($prices);
    }

    /**
     * @return HasMany<Variant>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(Variant::class);
    }

    /**
     * @return BelongsTo<Model3D, Product>
     */
    public function model3d(): BelongsTo
    {
        return $this->belongsTo(Model3D::class, 'model3d_id');
    }

    /**
     * Printable parts of a multi-part MODEL_3D figure, ordered as the source
     * shipped them (primary/largest first).
     *
     * @return HasMany<ProductModelPart>
     */
    public function modelParts(): HasMany
    {
        return $this->hasMany(ProductModelPart::class)->orderByDesc('is_primary')->orderBy('sort');
    }

    /**
     * The primary part of a multi-part figure - its file_ref mirrors
     * model_file_ref, the single source of truth for the printable mesh. (The
     * separate model3d_id records catalogue provenance/licence, not the mesh.)
     * Null for single-mesh products, which have no part rows.
     *
     * @return HasOne<ProductModelPart>
     */
    public function primaryPart(): HasOne
    {
        return $this->hasOne(ProductModelPart::class)->where('is_primary', true);
    }

    /**
     * Only publicly browsable products (no-account catalogue).
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('publish_state', PublishState::Published->value);
    }

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }
}
