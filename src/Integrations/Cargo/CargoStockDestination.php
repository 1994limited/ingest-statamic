<?php

namespace NineteenNinetyFour\Ingest\Integrations\Cargo;

use Illuminate\Support\Collection;
use NineteenNinetyFour\Ingest\Contracts\Destination;
use NineteenNinetyFour\Ingest\Feeds\Feed;
use NineteenNinetyFour\Ingest\IngestException;
use NineteenNinetyFour\Ingest\Mapping\Context;
use Statamic\Facades\Entry;
use Statamic\Fields\Field;

/**
 * Cargo stock and prices: a fast feed (every 15 minutes, say) of SKU,
 * price and stock that updates products already on the site. Each row is
 * matched by SKU to a simple product (its "sku" field) or a variant
 * option (its "sku" option field). Nothing is created or removed.
 *
 * Settings: collection, sku_field (default "sku").
 */
class CargoStockDestination implements Destination
{
    /** @var array<string, array{0: string, 1: ?string}>|null sku => [entry id, option key] */
    private ?array $index = null;

    public static function handle(): string
    {
        return 'cargo_stock';
    }

    public static function label(): string
    {
        return __('Cargo stock and prices');
    }

    public function contentType(): string
    {
        return 'stock';
    }

    public function personal(): bool
    {
        return false;
    }

    public function creates(): bool
    {
        return false;
    }

    public function validate(Feed $feed): void
    {
        if ($reason = Cargo::status()) {
            throw new IngestException($reason);
        }

        $collection = (string) ($feed->destination()['collection'] ?? '');
        if (! in_array($collection, Cargo::productCollections(), true)) {
            throw new IngestException(__('Choose one of Cargo\'s product collections.'));
        }
    }

    public function fields(Feed $feed): Collection
    {
        return collect([
            'price' => new Field('price', ['type' => 'money', 'display' => __('Price')]),
            'stock' => new Field('stock', ['type' => 'integer', 'display' => __('Stock')]),
        ]);
    }

    /**
     * The product (or variant option) with the row's SKU.
     */
    public function existing(Feed $feed, string $key = ''): ?StockTarget
    {
        $this->index ??= $this->buildIndex($feed);
        $match = $this->index[mb_strtolower(trim($key))] ?? null;

        return $match ? $this->target($match[0], $match[1]) : null;
    }

    public function find(Feed $feed, string $id): mixed
    {
        [$entry, $option] = array_pad(explode('::', $id, 2), 2, null);

        return $this->target($entry, $option ?: null);
    }

    public function findBy(Feed $feed, string $field, mixed $value): Collection
    {
        return collect([$this->existing($feed, (string) $value)])->filter();
    }

    public function values(Feed $feed, mixed $content, array $handles): array
    {
        /** @var StockTarget $content */
        return collect($handles)->mapWithKeys(fn ($h) => [$h => $content->get($h)])->all();
    }

    public function make(Feed $feed, array $values, Context $context): mixed
    {
        throw new IngestException(__('No product has this SKU.'));
    }

    public function fill(Feed $feed, mixed $content, array $values, Context $context): void
    {
        foreach ($values as $handle => $value) {
            $content->set($handle, $value);
        }
    }

    public function save(mixed $content): void
    {
        $entry = $content->entry;
        config('ingest.quiet_saves', true) ? $entry->saveQuietly() : $entry->save();
    }

    public function id(mixed $content): string
    {
        return $content->entry->id().($content->option !== null ? '::'.$content->option : '');
    }

    public function title(mixed $content): ?string
    {
        $title = (string) $content->entry->get('title');

        return $content->option !== null ? "{$title} ({$content->option})" : $title;
    }

    public function isPublished(mixed $content): bool
    {
        return true;
    }

    public function setPublished(mixed $content, bool $published): void {}

    public function delete(mixed $content): void
    {
        // A stock feed never removes products.
    }

    public function snapshot(mixed $content): array
    {
        return ['price' => $content->get('price'), 'stock' => $content->get('stock')];
    }

    public function restore(Feed $feed, array $snapshot): mixed
    {
        return null;
    }

    private function target(string $entryId, ?string $option): ?StockTarget
    {
        $entry = Entry::find($entryId);

        return $entry ? new StockTarget($entry, $option) : null;
    }

    /** @return array<string, array{0: string, 1: ?string}> */
    private function buildIndex(Feed $feed): array
    {
        $field = (string) ($feed->destination()['sku_field'] ?? 'sku');
        $index = [];

        $entries = Entry::query()
            ->where('collection', (string) ($feed->destination()['collection'] ?? 'products'))
            ->where('site', $feed->site())
            ->get();

        foreach ($entries as $entry) {
            if ($sku = $entry->get($field)) {
                $index[mb_strtolower(trim((string) $sku))] = [$entry->id(), null];
            }

            foreach ((array) ($entry->get('product_variants')['options'] ?? []) as $option) {
                if (! empty($option[$field])) {
                    $index[mb_strtolower(trim((string) $option[$field]))] = [$entry->id(), (string) $option['key']];
                }
            }
        }

        return $index;
    }
}
