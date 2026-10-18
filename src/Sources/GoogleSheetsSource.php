<?php

namespace NineteenNinetyFour\Ingest\Sources;

use Illuminate\Support\Facades\Cache;
use NineteenNinetyFour\Ingest\Contracts\Source;
use NineteenNinetyFour\Ingest\Feeds\Feed;
use NineteenNinetyFour\Ingest\Http\Fetcher;
use NineteenNinetyFour\Ingest\IngestException;
use NineteenNinetyFour\Ingest\Support\Secrets;

/**
 * A Google Sheet, read as CSV.
 *
 * Public sheets ("Anyone with the link can view"): paste the share link;
 * nothing else is needed. Private sheets: set ingest.google.service_account
 * to a service account's JSON key file, share the sheet with that
 * account's email address, and tick "private".
 *
 * Settings: url (the sheet's link), sheet (a tab name; otherwise the tab in
 * the link, or the first), private.
 */
class GoogleSheetsSource implements Source
{
    public function __construct(private Fetcher $fetcher) {}

    public static function handle(): string
    {
        return 'google_sheets';
    }

    public static function label(): string
    {
        return __('Google Sheets');
    }

    /**
     * @return array{id: string, gid: ?string}
     */
    public static function parse(string $url): array
    {
        if (! preg_match('#/spreadsheets/d/([a-zA-Z0-9_-]+)#', $url, $m)) {
            throw new IngestException('That isn\'t a Google Sheets link. Copy it from the sheet\'s Share button.');
        }

        $gid = preg_match('/[#&?]gid=(\d+)/', $url, $g) ? $g[1] : null;

        return ['id' => $m[1], 'gid' => $gid];
    }

    public function fetch(Feed $feed, string $directory, array $previous = []): Payload
    {
        $source = $feed->source();
        ['id' => $id, 'gid' => $gid] = self::parse((string) ($source['url'] ?? ''));
        $file = $directory.'/page-1';

        if (! empty($source['private'])) {
            $this->fetchPrivate($id, $gid, $source['sheet'] ?? null, $file);
        } else {
            $url = "https://docs.google.com/spreadsheets/d/{$id}/export?format=csv".(! empty($source['sheet'])
                ? '&sheet='.rawurlencode((string) $source['sheet'])
                : ($gid !== null ? "&gid={$gid}" : ''));

            try {
                $fetched = $this->fetcher->fetch($url, $file);
            } catch (IngestException $e) {
                throw new IngestException('Couldn\'t read the sheet. Is it shared as "Anyone with the link can view"? ('.$e->getMessage().')', 0, $e);
            }

            if (str_contains((string) $fetched->contentType(), 'html')) {
                throw new IngestException('Google returned a sign-in page, so the sheet isn\'t public. Share it as "Anyone with the link can view", or use a service account and tick "private".');
            }
        }

        return new Payload([[
            'path' => 'page-1',
            'url' => null,
            'content_type' => 'text/csv',
            'extension' => 'csv',
        ]], ['file_hash' => hash_file('sha1', $file)]);
    }

    private function fetchPrivate(string $id, ?string $gid, ?string $sheet, string $file): void
    {
        $token = $this->token();
        $headers = ['Authorization' => 'Bearer '.$token];
        $tmp = $file.'.json';

        if (! $sheet) {
            $this->fetcher->fetch("https://sheets.googleapis.com/v4/spreadsheets/{$id}", $tmp, $headers, ['fields' => 'sheets.properties(sheetId,title)']);
            $sheets = (array) (json_decode((string) file_get_contents($tmp), true)['sheets'] ?? []);
            $match = collect($sheets)->first(fn ($s) => $gid !== null && (string) ($s['properties']['sheetId'] ?? '') === $gid) ?? ($sheets[0] ?? null);
            $sheet = $match['properties']['title'] ?? null;

            if (! $sheet) {
                throw new IngestException('The spreadsheet has no sheets the service account can see.');
            }
        }

        $this->fetcher->fetch(
            "https://sheets.googleapis.com/v4/spreadsheets/{$id}/values/".rawurlencode($sheet),
            $tmp,
            $headers,
            ['valueRenderOption' => 'FORMATTED_VALUE', 'dateTimeRenderOption' => 'FORMATTED_STRING'],
        );

        $values = (array) (json_decode((string) file_get_contents($tmp), true)['values'] ?? []);
        @unlink($tmp);

        $out = fopen($file, 'wb');
        foreach ($values as $row) {
            fputcsv($out, array_map('strval', (array) $row), ',', '"', '');
        }
        fclose($out);
    }

    /**
     * A token for the service account (JWT bearer grant), cached for most of its hour.
     */
    private function token(): string
    {
        $path = (string) Secrets::resolve(config('ingest.google.service_account'));

        if ($path === '' || ! is_file($path)) {
            throw new IngestException('Private sheets need a service account: set INGEST_GOOGLE_SERVICE_ACCOUNT to the path of its JSON key file.');
        }

        $key = json_decode((string) file_get_contents($path), true);

        if (! isset($key['client_email'], $key['private_key'])) {
            throw new IngestException('The service account file isn\'t a Google JSON key.');
        }

        return Cache::remember('ingest:google:'.sha1($key['client_email']), 3300, function () use ($key) {
            $now = time();
            $segments = [
                self::b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
                self::b64(json_encode([
                    'iss' => $key['client_email'],
                    'scope' => 'https://www.googleapis.com/auth/spreadsheets.readonly',
                    'aud' => 'https://oauth2.googleapis.com/token',
                    'iat' => $now,
                    'exp' => $now + 3600,
                ])),
            ];

            if (! openssl_sign(implode('.', $segments), $signature, $key['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new IngestException('Couldn\'t sign with the service account key.');
            }
            $segments[] = self::b64($signature);

            $file = tempnam(sys_get_temp_dir(), 'ingest-google');
            try {
                $this->fetcher->fetch('https://oauth2.googleapis.com/token', $file, ['Content-Type' => 'application/x-www-form-urlencoded'], [], 'POST', http_build_query([
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => implode('.', $segments),
                ]), 1024 * 1024);
                $token = json_decode((string) file_get_contents($file), true)['access_token'] ?? null;
            } finally {
                @unlink($file);
            }

            if (! is_string($token)) {
                throw new IngestException('Google didn\'t return a token for the service account.');
            }

            return $token;
        });
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
