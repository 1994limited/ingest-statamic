<?php

namespace NineteenNinetyFour\Ingest\Assets;

use Illuminate\Support\Str;
use NineteenNinetyFour\Ingest\Contracts\ItemMap;
use NineteenNinetyFour\Ingest\Http\Fetcher;
use NineteenNinetyFour\Ingest\IngestException;
use NineteenNinetyFour\Ingest\Map\MapRecord;
use NineteenNinetyFour\Ingest\Mapping\Context;
use NineteenNinetyFour\Ingest\Mapping\FieldError;
use NineteenNinetyFour\Ingest\Support\Url;
use Statamic\Contracts\Assets\Asset as AssetContract;
use Statamic\Contracts\Assets\AssetContainer;
use Statamic\Facades\Asset;

/**
 * Downloads images and files from feeds into an asset container.
 *
 * The same URL is downloaded once: later runs reuse the asset (set
 * "refresh" to check for a changed file). Two URLs serving the same file
 * share one asset. Only allowed file types (checked from the file itself,
 * not the server's word) and sizes are kept; SVG is refused unless allowed,
 * as it can carry scripts.
 */
class AssetDownloader
{
    public const URLS = '_assets';

    public const HASHES = '_asset_hashes';

    private const EXTENSIONS = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/avif' => 'avif',
        'image/svg+xml' => 'svg', 'application/pdf' => 'pdf', 'video/mp4' => 'mp4', 'audio/mpeg' => 'mp3',
    ];

    public function __construct(private Fetcher $fetcher, private ItemMap $map) {}

    public function download(string $url, AssetContainer $container, string $folder, Context $context, array $options = []): ?AssetContract
    {
        $url = Url::resolve((string) $context->baseUrl, trim($url));

        if (! Url::isAbsolute($url)) {
            throw new FieldError("\"{$url}\" isn't a full URL, so it can't be downloaded. Set the feed's base URL or use the absolute_url transform.");
        }

        $urlKey = sha1($url);
        $record = $this->map->find(self::URLS, $container->handle(), $urlKey);
        $existing = $record ? Asset::find($record->contentId) : null;

        if ($existing && empty($options['refresh'])) {
            return $existing;
        }

        if ($context->dryRun) {
            $context->warn($existing ? "Would check {$url} for a new version." : "Would download {$url}.");

            return $existing;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'ingest-asset');

        try {
            try {
                $this->fetcher->fetch($url, $tmp, maxBytes: (int) config('ingest.assets.max_size', 20) * 1024 * 1024);
            } catch (IngestException $e) {
                throw new FieldError('Couldn\'t download '.Url::redact($url).': '.$e->getMessage());
            }

            $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
            $allowed = (array) config('ingest.assets.mime_types', []);

            if (! in_array($mime, $allowed, true)) {
                throw new FieldError("{$url} is a {$mime} file, which isn't an allowed asset type.");
            }

            $hash = sha1_file($tmp);

            if ($existing && $record->itemHash === $hash) {
                return $existing;
            }

            // A changed file at a URL already downloaded: replace it in place.
            if ($existing) {
                $container->disk()->put($existing->path(), fopen($tmp, 'rb'));
                $existing->writeMeta($existing->generateMeta());
                $record->itemHash = $hash;
                $this->map->put($record);

                return $existing;
            }

            // The same file from another URL.
            $byHash = $this->map->find(self::HASHES, $container->handle(), $hash);
            if ($byHash && ($asset = Asset::find($byHash->contentId))) {
                $this->remember($url, $urlKey, $container, $asset, $hash, $context);

                return $asset;
            }

            $path = $this->path($url, $mime, $folder, $container);
            $container->disk()->put($path, fopen($tmp, 'rb'));

            $asset = Asset::make()->container($container->handle())->path($path);
            if (! empty($options['alt'])) {
                $asset->set('alt', (string) $options['alt']);
            }
            $asset->writeMeta($asset->generateMeta());
            config('ingest.quiet_saves', true) ? $asset->saveQuietly() : $asset->save();

            $context->created[] = ['type' => 'asset', 'id' => $asset->id()];
            $this->remember($url, $urlKey, $container, $asset, $hash, $context);
            $this->map->put(new MapRecord(self::HASHES, $container->handle(), $hash, $url, 'asset', $asset->id(), $hash));

            return $asset;
        } finally {
            @unlink($tmp);
        }
    }

    private function remember(string $url, string $key, AssetContainer $container, AssetContract $asset, string $hash, Context $context): void
    {
        $this->map->put(new MapRecord(self::URLS, $container->handle(), $key, $url, 'asset', $asset->id(), $hash, firstRunId: $context->run?->id));
    }

    /**
     * A safe, unused path: the URL's file name, tidied, in the folder.
     */
    private function path(string $url, string $mime, string $folder, AssetContainer $container): string
    {
        $name = rawurldecode(basename((string) parse_url($url, PHP_URL_PATH)));
        $extension = self::EXTENSIONS[$mime] ?? strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $base = Str::slug(Str::ascii(pathinfo($name, PATHINFO_FILENAME))) ?: substr(sha1($url), 0, 12);
        $base = substr($base, 0, 100);

        $folder = trim(implode('/', array_map(fn ($s) => Str::slug(Str::ascii($s)) ?: 'files', array_filter(explode('/', $folder)))), '/');
        $prefix = $folder === '' ? '' : $folder.'/';

        $path = "{$prefix}{$base}.{$extension}";
        for ($i = 2; $container->disk()->exists($path); $i++) {
            $path = "{$prefix}{$base}-{$i}.{$extension}";
        }

        return $path;
    }
}
