<?php

namespace NineteenNinetyFour\Ingest\Feeds;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use NineteenNinetyFour\Ingest\Contracts\FeedRepository;
use Statamic\Facades\YAML;
use Throwable;

/**
 * Feeds as YAML files, one per feed (resources/ingest/feeds/{handle}.yaml),
 * so they're versioned and deployed with the site.
 */
class FileFeedRepository implements FeedRepository
{
    /** @var Collection<string, Feed>|null */
    private ?Collection $cache = null;

    public function __construct(private string $directory) {}

    public function all(): Collection
    {
        if ($this->cache) {
            return $this->cache;
        }

        $feeds = collect(File::isDirectory($this->directory) ? File::files($this->directory) : [])
            ->filter(fn ($file) => $file->getExtension() === 'yaml')
            ->mapWithKeys(function ($file) {
                $handle = $file->getBasename('.yaml');

                try {
                    return [$handle => Feed::make($handle, (array) YAML::parse(File::get($file->getPathname())))];
                } catch (Throwable $e) {
                    report($e);

                    return [];
                }
            })
            ->sortBy(fn (Feed $feed) => strtolower($feed->title()));

        return $this->cache = $feeds;
    }

    public function find(string $handle): ?Feed
    {
        return $this->all()->get($handle);
    }

    public function save(Feed $feed): void
    {
        File::ensureDirectoryExists($this->directory);
        File::put($this->path($feed->handle()), YAML::dump($feed->fileData()));
        $this->cache = null;
    }

    public function delete(Feed $feed): void
    {
        File::delete($this->path($feed->handle()));
        $this->cache = null;
    }

    private function path(string $handle): string
    {
        return $this->directory.'/'.$handle.'.yaml';
    }
}
