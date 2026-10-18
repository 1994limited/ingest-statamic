<?php

namespace NineteenNinetyFour\Ingest\Integrations\Cargo;

use Composer\InstalledVersions;
use DuncanMcClean\Cargo\ServiceProvider;

/**
 * Cargo (duncanmcclean/statamic-cargo) support: products, variants, prices
 * and stock. Switches itself off when Cargo isn't installed, or is a
 * version this wasn't built for, so the site carries on without it.
 */
class Cargo
{
    /** The Cargo releases this works with: 1.15 up to (not including) 2.0. */
    public const MINIMUM_VERSION = '1.15.0';

    public const BEFORE_VERSION = '2.0.0';

    private static ?bool $supported = null;

    public static function installed(): bool
    {
        return class_exists(ServiceProvider::class)
            && app()->providerIsLoaded(ServiceProvider::class)
            && self::supported();
    }

    public static function supported(?string $version = null): bool
    {
        if ($version === null && self::$supported !== null) {
            return self::$supported;
        }

        $checking = $version !== null;
        $version ??= class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('duncanmcclean/statamic-cargo')
            ? InstalledVersions::getVersion('duncanmcclean/statamic-cargo')
            : null;

        $supported = class_exists(ServiceProvider::class) && (
            // A development checkout: trust it if the classes are there.
            $version === null || ! preg_match('/^\d/', $version)
            || (version_compare($version, self::MINIMUM_VERSION, '>=') && version_compare($version, self::BEFORE_VERSION, '<'))
        );

        return $checking ? $supported : self::$supported = $supported;
    }

    /** Why the integration is off, for the Control Panel. */
    public static function status(): ?string
    {
        if (! class_exists(ServiceProvider::class)) {
            return __('Cargo isn\'t installed.');
        }

        if (! self::supported()) {
            return __('This version of Cargo isn\'t supported (Ingest works with Cargo 1.15 and later 1.x releases).');
        }

        return null;
    }

    /** @return string[] */
    public static function productCollections(): array
    {
        return (array) config('statamic.cargo.products.collections', ['products']);
    }

    /** For tests. */
    public static function flush(): void
    {
        self::$supported = null;
    }
}
