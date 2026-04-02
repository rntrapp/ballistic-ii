<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

final class AppSetting extends Model
{
    use Auditable;

    protected $fillable = ['key', 'value'];

    /**
     * @return array{value: string}
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }

    /**
     * Get a setting value by key, with caching.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::remember("app_setting:{$key}", 60, function () use ($key, $default) {
            $setting = self::where('key', $key)->first();

            return $setting?->value ?? $default;
        });
    }

    /**
     * Set a setting value by key, busting the cache.
     */
    public static function set(string $key, mixed $value): void
    {
        self::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );

        Cache::forget("app_setting:{$key}");
    }

    /**
     * Get the global feature flags, merged with safe defaults (all true).
     * Results are cached for 60 seconds via the application cache, so
     * repeated calls within a request hit only the cache, not the database.
     *
     * @return array<string, bool>
     */
    public static function globalFeatureFlags(): array
    {
        $defaults = [
            'dates' => true,
            'delegation' => true,
        ];

        /** @var array<string, bool>|null $stored */
        $stored = self::get('feature_flags');

        if (! is_array($stored)) {
            return $defaults;
        }

        return array_merge($defaults, $stored);
    }
}
