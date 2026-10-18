<?php

namespace NineteenNinetyFour\Ingest\Sources;

use Illuminate\Support\Facades\Cache;
use NineteenNinetyFour\Ingest\Http\Fetcher;
use NineteenNinetyFour\Ingest\IngestException;
use NineteenNinetyFour\Ingest\Support\Secrets;

/**
 * Turns a feed's auth settings into request headers and query parameters.
 *
 *   none
 *   bearer     token
 *   basic      username, password
 *   api_key    name, value, in (header|query)
 *   oauth2     token_url, client_id, client_secret, scope (client credentials)
 *
 * Secret values are env references ("env:NAME").
 */
class Auth
{
    public function __construct(private Fetcher $fetcher) {}

    /**
     * @return array{headers: array<string, string>, query: array<string, string>}
     */
    public function apply(array $auth, string $feedHandle): array
    {
        $type = $auth['type'] ?? 'none';
        $secret = fn (string $key) => $this->secret($auth, $key);

        return match ($type) {
            'none', null, '' => ['headers' => [], 'query' => []],
            'bearer' => ['headers' => ['Authorization' => 'Bearer '.$secret('token')], 'query' => []],
            'basic' => ['headers' => ['Authorization' => 'Basic '.base64_encode(Secrets::resolve($auth['username'] ?? '').':'.$secret('password'))], 'query' => []],
            'api_key' => ($auth['in'] ?? 'header') === 'query'
                ? ['headers' => [], 'query' => [(string) ($auth['name'] ?? 'api_key') => $secret('value')]]
                : ['headers' => [(string) ($auth['name'] ?? 'X-API-Key') => $secret('value')], 'query' => []],
            'oauth2' => ['headers' => ['Authorization' => 'Bearer '.$this->oauthToken($auth, $feedHandle)], 'query' => []],
            default => throw new IngestException("Unknown authentication type [{$type}]."),
        };
    }

    private function secret(array $auth, string $key): string
    {
        $value = $auth[$key] ?? null;

        if (Secrets::isReference($value)) {
            $resolved = Secrets::resolve($value);

            if ($resolved === null || $resolved === '') {
                throw new IngestException(sprintf('The environment variable %s, used for this feed\'s %s, isn\'t set.', Secrets::name($value), $key));
            }

            return $resolved;
        }

        if ($value === null || $value === '') {
            throw new IngestException("The feed's authentication needs a {$key}.");
        }

        return (string) $value;
    }

    /**
     * An OAuth2 client-credentials token, cached until shortly before it expires.
     */
    private function oauthToken(array $auth, string $feedHandle): string
    {
        $tokenUrl = (string) ($auth['token_url'] ?? '');
        $clientId = (string) Secrets::resolve($auth['client_id'] ?? '');
        $cacheKey = 'ingest:oauth:'.sha1($feedHandle.'|'.$tokenUrl.'|'.$clientId);

        if ($token = Cache::get($cacheKey)) {
            return $token;
        }

        if ($tokenUrl === '' || $clientId === '') {
            throw new IngestException('OAuth needs a token URL and a client ID.');
        }

        $body = http_build_query(array_filter([
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'client_secret' => $this->secret($auth, 'client_secret'),
            'scope' => $auth['scope'] ?? null,
        ]));

        $file = tempnam(sys_get_temp_dir(), 'ingest-oauth');

        try {
            $this->fetcher->fetch($tokenUrl, $file, ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'], [], 'POST', $body, 1024 * 1024);
            $data = json_decode((string) file_get_contents($file), true);
        } finally {
            @unlink($file);
        }

        $token = $data['access_token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw new IngestException('The OAuth token URL didn\'t return an access_token.');
        }

        $ttl = max(60, (int) ($data['expires_in'] ?? 3600) - 60);
        Cache::put($cacheKey, $token, $ttl);

        return $token;
    }
}
