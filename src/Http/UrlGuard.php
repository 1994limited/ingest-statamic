<?php

namespace NineteenNinetyFour\Ingest\Http;

use NineteenNinetyFour\Ingest\IngestException;

/**
 * Stops feeds, pagination links and asset downloads from reaching the
 * server's own network (SSRF): only http(s), and only hosts that resolve to
 * public addresses, unless a host is allowed in config. The address that
 * was checked is the one the request connects to, so a DNS answer can't
 * change between the check and the request.
 */
class UrlGuard
{
    /** @var callable(string): string[] */
    private $resolver;

    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver ?? self::dns(...);
    }

    /**
     * Check a URL and return the address to connect to (null when the host
     * is allowed by config and connects as usual).
     */
    public function check(string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new IngestException("Not a full URL: {$url}");
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new IngestException("Only http and https URLs can be fetched, not {$parts['scheme']}.");
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new IngestException('Put credentials in the feed\'s authentication settings, not in the URL.');
        }

        $host = strtolower(trim($parts['host'], '[]'));

        if ($this->allowed($host)) {
            return null;
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ($this->resolver)($host);

        if ($addresses === []) {
            throw new IngestException("Couldn't resolve {$host}.");
        }

        foreach ($addresses as $address) {
            if (! self::isPublic($address)) {
                throw new IngestException("{$host} points to a private or internal address ({$address}). Add it to ingest.allowed_hosts if that's intended.");
            }
        }

        return $addresses[0];
    }

    public function allowed(string $host): bool
    {
        foreach ((array) config('ingest.allowed_hosts', []) as $pattern) {
            $pattern = strtolower(trim((string) $pattern));
            if ($pattern !== '' && fnmatch($pattern, $host)) {
                return true;
            }
        }

        return false;
    }

    public static function isPublic(string $address): bool
    {
        $address = strtolower($address);

        // IPv4 written as IPv6 (::ffff:10.0.0.1) is judged as IPv4.
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/', $address, $m)) {
            $address = $m[1];
        }

        if (! filter_var($address, FILTER_VALIDATE_IP)) {
            return false;
        }

        return (bool) filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    /** @return string[] */
    private static function dns(string $host): array
    {
        $addresses = gethostbynamel($host) ?: [];

        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }
}
