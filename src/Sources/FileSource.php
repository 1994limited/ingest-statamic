<?php

namespace NineteenNinetyFour\Ingest\Sources;

use Illuminate\Support\Facades\File;
use NineteenNinetyFour\Ingest\Contracts\Source;
use NineteenNinetyFour\Ingest\Feeds\Feed;
use NineteenNinetyFour\Ingest\IngestException;

/**
 * A file uploaded in the Control Panel, kept privately in
 * storage/ingest/uploads/{feed}/. Settings: file (the stored name).
 */
class FileSource implements Source
{
    public static function handle(): string
    {
        return 'upload';
    }

    public static function label(): string
    {
        return __('Uploaded file');
    }

    public static function directory(string $feed): string
    {
        return rtrim((string) config('ingest.drivers.'.config('ingest.driver', 'file').'.storage', storage_path('ingest')), '/').'/uploads/'.$feed;
    }

    public function fetch(Feed $feed, string $directory, array $previous = []): Payload
    {
        $name = basename((string) ($feed->source()['file'] ?? ''));
        $path = self::directory($feed->handle()).'/'.$name;

        if ($name === '' || ! is_file($path)) {
            throw new IngestException('Upload a file for this feed first.');
        }

        return $this->copy($path, $directory, $previous);
    }

    protected function copy(string $path, string $directory, array $previous): Payload
    {
        $hash = hash_file('sha1', $path);

        File::ensureDirectoryExists($directory);
        copy($path, $directory.'/page-1');

        return new Payload([[
            'path' => 'page-1',
            'url' => null,
            'content_type' => null,
            'extension' => strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: null,
        ]], ['file_hash' => $hash]);
    }
}
