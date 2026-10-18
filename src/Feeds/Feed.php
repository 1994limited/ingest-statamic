<?php

namespace NineteenNinetyFour\Ingest\Feeds;

use Illuminate\Support\Arr;
use NineteenNinetyFour\Ingest\IngestException;
use Statamic\Facades\Site;

/**
 * A saved feed: where to fetch from, how to read it, where items go, how
 * fields are mapped, and the sync rules. Stored by handle, so the same feed
 * deploys from one environment to another.
 */
class Feed
{
    public const DEFAULTS = [
        'title' => null,
        'enabled' => true,
        'source' => ['type' => 'url'],
        'format' => ['type' => 'auto', 'item_path' => null],
        'filters' => [],
        'destination' => ['type' => 'entries', 'site' => null],
        'key' => [],
        'sync' => [
            'create' => true,
            'update' => true,
            'publish_new' => true,
            'adopt' => null,
            'missing' => ['action' => 'leave', 'delete_after_days' => null],
            'safety' => ['max_removal_percent' => null, 'allow_empty' => false],
            'local_edits' => 'overwrite',
            'on_field_error' => 'save_rest',
            'limit' => null,
        ],
        'mapping' => [],
        'schedule' => ['frequency' => 'manual'],
        'then_run' => [],
        'notify' => ['on' => ['failed', 'aborted'], 'stale_after_hours' => null],
        'hook' => null,
        'webhook' => ['token_hash' => null],
        'localizes' => null,
    ];

    /** @param array<string, mixed> $data */
    public function __construct(private string $handle, private array $data = [])
    {
        if (! preg_match('/^[a-z0-9][a-z0-9_-]*$/', $handle)) {
            throw new IngestException("Feed handles use lower-case letters, numbers, dashes and underscores: [{$handle}].");
        }

        $this->data = array_replace_recursive(self::DEFAULTS, $data);

        // Lists replace rather than merge.
        foreach (['key', 'filters', 'then_run', 'mapping'] as $list) {
            if (array_key_exists($list, $data)) {
                $this->data[$list] = $data[$list] ?? [];
            }
        }
        if (isset($data['notify']['on'])) {
            $this->data['notify']['on'] = $data['notify']['on'];
        }
    }

    public static function make(string $handle, array $data = []): self
    {
        return new self($handle, $data);
    }

    public function handle(): string
    {
        return $this->handle;
    }

    public function title(): string
    {
        return (string) ($this->data['title'] ?: str($this->handle)->replace(['-', '_'], ' ')->title());
    }

    public function enabled(): bool
    {
        return (bool) $this->data['enabled'];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->data, $key, $default);
    }

    public function set(string $key, mixed $value): self
    {
        Arr::set($this->data, $key, $value);

        return $this;
    }

    /** @return array<string, mixed> */
    public function source(): array
    {
        return (array) $this->data['source'];
    }

    /** @return array<string, mixed> */
    public function format(): array
    {
        return (array) $this->data['format'];
    }

    /** @return array<string, mixed> */
    public function destination(): array
    {
        return (array) $this->data['destination'];
    }

    /** @return string[] */
    public function key(): array
    {
        return array_values(array_filter((array) $this->data['key'], fn ($k) => is_string($k) && $k !== ''));
    }

    /** @return array<string, mixed> */
    public function sync(): array
    {
        return (array) $this->data['sync'];
    }

    /** @return array<string, array<string, mixed>> */
    public function mapping(): array
    {
        return array_filter((array) $this->data['mapping'], 'is_array');
    }

    /** @return array<int, array<string, mixed>> */
    public function filters(): array
    {
        return array_values(array_filter((array) $this->data['filters'], 'is_array'));
    }

    /** @return string[] */
    public function thenRun(): array
    {
        return array_values(array_filter((array) $this->data['then_run'], 'is_string'));
    }

    public function site(): string
    {
        return (string) ($this->data['destination']['site'] ?? null ?: Site::default()->handle());
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * What's saved: the full settings, minus empty defaults, so files stay short.
     *
     * @return array<string, mixed>
     */
    public function fileData(): array
    {
        return self::prune($this->data, self::DEFAULTS);
    }

    private static function prune(array $data, array $defaults): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $default = $defaults[$key] ?? null;

            if (is_array($value) && is_array($default) && ! array_is_list($value) && $default !== [] && ! array_is_list($default)) {
                $pruned = self::prune($value, $default);
                if ($pruned !== []) {
                    $out[$key] = $pruned;
                }

                continue;
            }

            if (array_key_exists($key, $defaults) && $value === $default) {
                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }
}
