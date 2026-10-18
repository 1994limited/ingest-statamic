<?php

namespace NineteenNinetyFour\Ingest;

use Statamic\Facades\Addon;
use Throwable;

/**
 * Settings editable in the Control Panel (Tools → Addons → Ingest →
 * Settings), falling back to config/ingest.php. Secrets are never here:
 * they stay in .env.
 */
class Settings
{
    public const PACKAGE = '1994/ingest-statamic';

    /** Setting => the config key it falls back to. */
    private const CONFIG = [
        'notify_mail' => 'ingest.notifications.mail',
        'max_removal_percent' => 'ingest.safety.max_removal_percent',
        'keep_runs' => 'ingest.history.keep_runs',
        'keep_failed_days' => 'ingest.history.keep_failed_days',
        'chunk_size' => 'ingest.chunk_size',
    ];

    private static ?array $values = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        self::$values ??= self::load();

        $value = self::$values[$key] ?? null;

        if ($value === null || $value === '' || $value === []) {
            return isset(self::CONFIG[$key]) ? config(self::CONFIG[$key], $default) : $default;
        }

        return $value;
    }

    /** @return string[] */
    public static function list(string $key): array
    {
        $value = self::get($key, []);
        $list = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(array_map(fn ($v) => trim((string) $v), $list)));
    }

    public static function int(string $key, int $default, int $min = 0, int $max = PHP_INT_MAX): int
    {
        return max($min, min($max, (int) self::get($key, $default)));
    }

    public static function flush(): void
    {
        self::$values = null;
    }

    /**
     * Copy saved settings into config, so code reading config sees them.
     */
    public static function apply(): void
    {
        self::flush();

        foreach (self::CONFIG as $key => $config) {
            $value = self::load()[$key] ?? null;
            if ($value !== null && $value !== '' && $value !== []) {
                config([$config => $key === 'notify_mail' ? self::list($key) : $value]);
            }
        }
    }

    private static function load(): array
    {
        try {
            return (array) (Addon::get(self::PACKAGE)?->settings()?->raw() ?? []);
        } catch (Throwable) {
            return [];
        }
    }
}
