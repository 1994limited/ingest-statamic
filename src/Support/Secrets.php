<?php

namespace NineteenNinetyFour\Ingest\Support;

use Dotenv\Dotenv;
use Illuminate\Support\Env;

/**
 * Feeds never store secrets: a token or password is written as
 * "env:SUPPLIER_TOKEN" and read from the environment when the feed runs.
 *
 * Laravel's env() returns nothing once config is cached, so the .env file
 * is read directly as a fallback.
 */
class Secrets
{
    /** @var array<string, string>|null */
    private static ?array $dotenv = null;

    public static function isReference(mixed $value): bool
    {
        return is_string($value) && preg_match('/^env:[A-Z0-9_]+$/i', trim($value)) === 1;
    }

    public static function name(string $reference): string
    {
        return substr(trim($reference), 4);
    }

    /**
     * The value of an env reference; any other value is returned as it is.
     */
    public static function resolve(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::resolve(...), $value);
        }

        if (! self::isReference($value)) {
            return $value;
        }

        return self::get(self::name($value));
    }

    public static function get(string $name): ?string
    {
        $value = config("ingest.secrets.{$name}") ?? Env::get($name);

        if ($value === null) {
            self::$dotenv ??= self::readDotenv();
            $value = self::$dotenv[$name] ?? null;
        }

        return $value === null ? null : (string) $value;
    }

    public static function exists(string $name): bool
    {
        return self::get($name) !== null && self::get($name) !== '';
    }

    /** For tests. */
    public static function flush(): void
    {
        self::$dotenv = null;
    }

    private static function readDotenv(): array
    {
        $path = app()->environmentFilePath();

        if (! is_file($path)) {
            return [];
        }

        try {
            return Dotenv::parse((string) file_get_contents($path));
        } catch (\Throwable) {
            return [];
        }
    }
}
