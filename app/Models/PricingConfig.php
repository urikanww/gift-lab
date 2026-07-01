<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

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

    /**
     * TTL (seconds) for the shared cache layer. Bounds cross-process staleness
     * under a long-lived runtime (Octane / queue workers) where a config row
     * updated by another process would otherwise never be observed. A write in
     * *this* process still forgets the key immediately (see booted()).
     */
    private const CACHE_TTL_SECONDS = 30;

    /**
     * Request-scoped memo of resolved group:key => value, layered on top of the
     * shared cache. Collapses repeated reads within a single request to one
     * lookup; the shared cache collapses reads across requests and bounds
     * cross-process staleness to CACHE_TTL_SECONDS.
     *
     * @var array<string, mixed>
     */
    private static array $memo = [];

    protected function casts(): array
    {
        return [
            'value' => 'array',
            'is_money' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saved(static function (self $config): void {
            static::$memo = [];
            Cache::forget(static::cacheKey($config->group, $config->key));
        });

        static::deleted(static function (self $config): void {
            static::$memo = [];
            Cache::forget(static::cacheKey($config->group, $config->key));
        });
    }

    /**
     * Fetch a single config value by group+key, or a default. Resolved from the
     * per-request memo, then the shared cache (TTL-bounded for cross-process
     * consistency), then the DB. A missing row resolves to $default.
     */
    public static function value(string $group, string $key, mixed $default = null): mixed
    {
        $memoKey = $group.':'.$key;

        if (! array_key_exists($memoKey, static::$memo)) {
            static::$memo[$memoKey] = Cache::remember(
                static::cacheKey($group, $key),
                self::CACHE_TTL_SECONDS,
                static fn () => static::query()
                    ->where('group', $group)
                    ->where('key', $key)
                    ->first()?->value,
            );
        }

        return static::$memo[$memoKey] ?? $default;
    }

    private static function cacheKey(string $group, string $key): string
    {
        return 'pricing_config:'.$group.':'.$key;
    }

    /**
     * Clear the per-request memo. Primarily for test isolation — the static memo
     * persists for the whole PHP process, so a value read in one test would leak
     * into the next. In production each request/worker tick starts fresh.
     */
    public static function flushMemo(): void
    {
        static::$memo = [];
    }
}
