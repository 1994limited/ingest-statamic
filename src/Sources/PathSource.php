<?php

namespace NineteenNinetyFour\Ingest\Sources;

use Illuminate\Support\Facades\File;
use NineteenNinetyFour\Ingest\Feeds\Feed;
use NineteenNinetyFour\Ingest\IngestException;

/**
 * A file on the server: an export an ERP or supplier drops by FTP, say.
 * Only folders listed in ingest.server_paths can be read.
 *
 * Settings: path, archive (move the file into an "archive" folder beside
 * it after a successful run).
 */
class PathSource extends FileSource
{
    public static function handle(): string
    {
        return 'path';
    }

    public static function label(): string
    {
        return __('File on the server');
    }

    public function fetch(Feed $feed, string $directory, array $previous = []): Payload
    {
        $path = self::allowed((string) ($feed->source()['path'] ?? ''));

        if (! is_file($path)) {
            throw new IngestException('There\'s no file at '.$path.' yet.');
        }

        $payload = $this->copy($path, $directory, $previous);
        $payload->validators['source_path'] = $path;

        return $payload;
    }

    /**
     * The real path, if it's inside an allowed folder.
     */
    public static function allowed(string $path): string
    {
        $path = trim($path);
        $real = realpath($path);

        if ($path === '' || $real === false) {
            // Not there yet: check the folder it would be in.
            $folder = realpath(dirname($path));
            if ($folder === false) {
                throw new IngestException("The folder for {$path} doesn't exist.");
            }
            $real = $folder.'/'.basename($path);
        }

        foreach ((array) config('ingest.server_paths', []) as $root) {
            $root = realpath((string) $root);
            if ($root !== false && str_starts_with($real, rtrim($root, '/').'/')) {
                return $real;
            }
        }

        throw new IngestException("{$path} isn't in a folder Ingest may read. Add the folder to ingest.server_paths.");
    }

    /**
     * Move the source file aside once a run has used it.
     */
    public static function archive(string $path): void
    {
        if (! is_file($path)) {
            return;
        }

        $archive = dirname($path).'/archive';
        File::ensureDirectoryExists($archive);
        rename($path, $archive.'/'.date('Ymd-His').'-'.basename($path));
    }
}
