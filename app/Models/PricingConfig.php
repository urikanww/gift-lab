<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Dynamic pricing config, read by the quote engine at quote time.
 *
 * @property string $group
 * @property string $key
 * @property mixed $value
 * @property bool $is_money
 */
class PricingConfig extends Model
{
    protected $fillable = [
        'group',
        'key',
        'value',
        'label',
        'is_money',
        'currency',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'array',
            'is_money' => 'boolean',
        ];
    }

    /**
     * Fetch a single config value by group+key, or a default.
     */
    public static function value(string $group, string $key, mixed $default = null): mixed
    {
        $row = static::query()
            ->where('group', $group)
            ->where('key', $key)
            ->first();

        return $row?->value ?? $default;
    }
}
