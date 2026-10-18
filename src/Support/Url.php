<?php

namespace NineteenNinetyFour\Ingest\Support;

/**
 * Resolving links found in feeds (next pages, images) against the URL they
 * came from.
 */
class Url
{
    public static function resolve(string $base, string $relative): string
    {
        $relative = trim($relative);

        if ($relative === '') {
            return $base;
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $relative)) {
            return $relative;
        }

        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'https';
        $authority = $scheme.'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($relative, '//')) {
            return $scheme.':'.$relative;
        }

        if (str_starts_with($relative, '?')) {
            return $authority.($parts['path'] ?? '/').$relative;
        }

        if (str_starts_with($relative, '/')) {
            return $authority.self::normalise($relative);
        }

        $dir = preg_replace('#/[^/]*$#', '/', $parts['path'] ?? '/');

        return $authority.self::normalise($dir.$relative);
    }

    public static function isAbsolute(string $url): bool
    {
        return (bool) preg_match('#^https?://#i', $url);
    }

    private static function normalise(string $path): string
    {
        [$path, $query] = array_pad(explode('?', $path, 2), 2, null);
        $out = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                array_pop($out);
            } elseif ($segment !== '.') {
                $out[] = $segment;
            }
        }

        $path = implode('/', $out);
        $path = str_starts_with($path, '/') ? $path : '/'.$path;

        return $query !== null ? $path.'?'.$query : $path;
    }
}
