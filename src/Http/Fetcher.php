<?php

namespace NineteenNinetyFour\Ingest\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use NineteenNinetyFour\Ingest\IngestException;
use NineteenNinetyFour\Ingest\Support\Url;

/**
 * Downloads a URL to a file: through the SSRF guard on every hop, with
 * timeouts and a size limit, never holding the whole body in memory.
 */
class Fetcher
{
    public function __construct(private UrlGuard $guard) {}

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $query
     */
    public function fetch(
        string $url,
        string $destination,
        array $headers = [],
        array $query = [],
        string $method = 'GET',
        ?string $body = null,
        ?int $maxBytes = null,
    ): Fetched {
        $maxBytes ??= (int) config('ingest.http.max_feed_size', 200) * 1024 * 1024;
        $hops = 0;

        while (true) {
            if (! is_dir(dirname($destination))) {
                mkdir(dirname($destination), 0755, true);
            }

            $response = $this->send($url, $headers, $query, $method, $body, $destination, $maxBytes);

            if (in_array($response->status(), [301, 302, 303, 307, 308], true) && $response->header('Location')) {
                if (++$hops > (int) config('ingest.http.max_redirects', 3)) {
                    throw new IngestException("Too many redirects fetching {$url}.");
                }

                $url = Url::resolve($url, $response->header('Location'));
                // Query parameters belong to the first URL only.
                $query = [];
                if ($response->status() === 303) {
                    [$method, $body] = ['GET', null];
                }

                continue;
            }

            break;
        }

        $headersOut = array_change_key_case(array_map(fn ($v) => is_array($v) ? implode(', ', $v) : $v, $response->headers()), CASE_LOWER);

        if ($response->status() === 304) {
            return new Fetched(null, 304, $url, $headersOut, 0);
        }

        if ($response->status() >= 400) {
            throw new IngestException(sprintf('%s answered %d %s.', parse_url($url, PHP_URL_HOST), $response->status(), $response->reason()));
        }

        if (($length = (int) $response->header('Content-Length')) > $maxBytes) {
            throw new IngestException(sprintf('%s is %s, larger than the %s limit.', $url, self::size($length), self::size($maxBytes)));
        }

        $size = $this->save($response, $destination, $maxBytes, $url);

        return new Fetched($destination, $response->status(), $url, $headersOut, $size);
    }

    private function send(string $url, array $headers, array $query, string $method, ?string $body, ?string $sink = null, ?int $maxBytes = null): Response
    {
        $address = $this->guard->check($url);
        $maxBytes ??= (int) config('ingest.http.max_feed_size', 200) * 1024 * 1024;

        // Curl writes the body straight to a file. (Guzzle's "stream" option
        // would switch to PHP's stream handler, which ignores the pinned
        // address below.)
        $options = [
            'allow_redirects' => false,
            'sink' => $sink ?? 'php://temp',
            'on_headers' => function ($response) use ($maxBytes, $url) {
                if ((int) $response->getHeaderLine('Content-Length') > $maxBytes) {
                    throw new IngestException(sprintf('%s is larger than the %s limit.', $url, self::size($maxBytes)));
                }
            },
            'progress' => function ($total, $downloaded) use ($maxBytes, $url) {
                if ($downloaded > $maxBytes) {
                    throw new IngestException(sprintf('%s is larger than the %s limit.', $url, self::size($maxBytes)));
                }
            },
            'timeout' => (int) config('ingest.http.timeout', 30),
            'connect_timeout' => (int) config('ingest.http.connect_timeout', 10),
            'verify' => self::verify(),
        ];

        if ($address !== null) {
            $parts = parse_url($url);
            $port = $parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80);
            $ip = str_contains($address, ':') ? "[{$address}]" : $address;
            // Connect to the address that was checked.
            $options['curl'] = [CURLOPT_RESOLVE => ["{$parts['host']}:{$port}:{$ip}"]];
        }

        $request = Http::withOptions($options)
            ->withHeaders(['User-Agent' => (string) config('ingest.http.user_agent', 'Ingest for Statamic')] + $headers);

        if ($query !== []) {
            $request = $request->withQueryParameters($query);
        }

        if ($body !== null) {
            $request = $request->withBody($body, $headers['Content-Type'] ?? 'application/json');
        }

        try {
            return $request->send($method, $url);
        } catch (ConnectionException $e) {
            // A limit thrown from a callback arrives wrapped.
            for ($previous = $e; $previous; $previous = $previous->getPrevious()) {
                if ($previous instanceof IngestException) {
                    throw $previous;
                }
            }

            throw new IngestException("Couldn't connect to ".parse_url($url, PHP_URL_HOST).': '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * The body is in the file already (curl's sink); a faked response in
     * tests isn't, so write it there.
     */
    private function save(Response $response, string $destination, int $maxBytes, string $url): int
    {
        clearstatcache(true, $destination);

        if (! is_file($destination) || filesize($destination) === 0) {
            $body = $response->body();

            if (strlen($body) > $maxBytes) {
                @unlink($destination);

                throw new IngestException(sprintf('%s is larger than the %s limit.', $url, self::size($maxBytes)));
            }

            file_put_contents($destination, $body);
        }

        $size = (int) filesize($destination);

        if ($size > $maxBytes) {
            @unlink($destination);

            throw new IngestException(sprintf('%s is larger than the %s limit.', $url, self::size($maxBytes)));
        }

        return $size;
    }

    private static function verify(): bool|string
    {
        $verify = config('ingest.http.verify', true);

        if (is_string($verify) && ! in_array(strtolower($verify), ['true', 'false', '1', '0', ''], true)) {
            return $verify;
        }

        return filter_var($verify, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
    }

    public static function size(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1).' MB' : round($bytes / 1024).' KB';
    }
}
