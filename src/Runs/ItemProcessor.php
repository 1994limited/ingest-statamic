<?php

namespace NineteenNinetyFour\Ingest\Runs;

use Illuminate\Support\Collection;
use NineteenNinetyFour\Ingest\Contracts\Destination;
use NineteenNinetyFour\Ingest\Contracts\ItemMap;
use NineteenNinetyFour\Ingest\Destinations\Destinations;
use NineteenNinetyFour\Ingest\Events\ItemFailed;
use NineteenNinetyFour\Ingest\Events\ItemParsed;
use NineteenNinetyFour\Ingest\Events\ItemSaved;
use NineteenNinetyFour\Ingest\Events\ItemSaving;
use NineteenNinetyFour\Ingest\Feeds\Feed;
use NineteenNinetyFour\Ingest\Hooks\FeedHook;
use NineteenNinetyFour\Ingest\Ingest;
use NineteenNinetyFour\Ingest\IngestException;
use NineteenNinetyFour\Ingest\Map\MapRecord;
use NineteenNinetyFour\Ingest\Mapping\Context;
use NineteenNinetyFour\Ingest\Mapping\FieldError;
use NineteenNinetyFour\Ingest\Mapping\Mapper;
use NineteenNinetyFour\Ingest\Support\Filters;
use NineteenNinetyFour\Ingest\Support\Value;
use Statamic\Fields\Field;
use Throwable;

/**
 * Takes one chunk of a run's items through filter → match → map →
 * compare → save, and records what happened to each.
 */
class ItemProcessor
{
    private Feed $feed;

    private Run $run;

    private Destination $destination;

    private ?FeedHook $hook;

    /** @var Collection<string, Field> */
    private Collection $fields;

    private Context $context;

    public function __construct(
        private ItemMap $map,
        private Mapper $mapper,
        private Destinations $destinations,
        private Ingest $ingest,
    ) {}

    /**
     * @return RunItem[] the rows worth keeping (not the unchanged ones)
     */
    public function chunk(Feed $feed, Run $run, ItemsFile $items, int $index): array
    {
        $this->feed = $feed;
        $this->run = $run;
        $this->destination = $this->destinations->for($feed);
        $this->hook = $this->ingest->hookFor($feed);
        $this->fields = $this->destination->fields($feed);
        $this->context = new Context($feed, $run, $run->dryRun, $run->payload['base_url'] ?? null);

        $rows = [];
        $seen = [];

        foreach ($items->chunk($index) as $row) {
            $run->processed++;

            $item = (array) $row['i'];
            $this->context->forItem($item);

            try {
                $result = $this->item($row, $item, $seen, $items);
            } catch (Throwable $e) {
                if (! $e instanceof IngestException && ! $e instanceof FieldError) {
                    report($e);
                }
                $result = $this->row((string) ($row['s'] ?? ''), RunItem::FAILED, errors: [$e->getMessage()]);
                event(new ItemFailed($feed, $run, $item, $e->getMessage()));
            }

            if ($result === null) {
                continue;
            }

            $run->count($result->action);
            if ($result->warnings !== [] && $result->action !== RunItem::FAILED) {
                $run->count('warnings');
            }
            $result->position = (int) $row['p'];

            if ($result->action !== RunItem::UNCHANGED || $result->warnings !== []) {
                $rows[] = $result;
            }
        }

        $items->markSeen($seen);
        $this->map->flush();

        return $rows;
    }

    private function item(array $row, array $item, array &$seen, ItemsFile $items): ?RunItem
    {
        $sourceKey = (string) ($row['s'] ?? '');

        if (! Filters::passes($item, $this->feed->filters())) {
            $this->run->count('skipped');

            return null;
        }

        if ($row['k'] === null) {
            return $this->row('#'.((int) $row['p'] + 1), RunItem::FAILED, errors: [__('The item has no value for the feed\'s key (:key).', ['key' => implode(', ', $this->feed->key()) ?: __('none set')])]);
        }

        if ($row['d']) {
            return $this->row($sourceKey, RunItem::SKIPPED, warnings: [__('Another item earlier in the feed has the same key, so this one was left out.')]);
        }

        $key = (string) $row['k'];
        $seen[] = $key;

        if ($this->hook) {
            $item = $this->hook->item($item, $this->context);
            if ($item === null) {
                return $this->row($sourceKey, RunItem::SKIPPED, warnings: [__('Skipped by the feed\'s hook.')]);
            }
        }

        $event = new ItemParsed($this->feed, $item, $this->context);
        event($event);
        if ($event->skip) {
            return $this->row($sourceKey, RunItem::SKIPPED, warnings: [__('Skipped by an ItemParsed listener.')]);
        }
        $item = $event->item;
        $this->context->item = $item;

        $site = $this->feed->site();
        $record = $this->map->find($this->feed->handle(), $site, $key);
        $content = $record ? $this->destination->find($this->feed, $record->contentId) : null;
        $warnings = [];

        if ($record && ! $content) {
            $warnings[] = __('This item had been deleted from the site, so it was created again.');
            $record = null;
        }

        if (! $content && ($adopt = $this->feed->sync()['adopt'] ?? null)) {
            [$content, $error] = $this->adopt((array) $adopt, $item);
            if ($error) {
                return $this->row($sourceKey, RunItem::FAILED, errors: [$error]);
            }
        }

        $isNew = ! $content;
        $this->context->isNew = $isNew;

        if ($isNew && ! ($this->feed->sync()['create'] ?? true)) {
            return $this->row($sourceKey, RunItem::SKIPPED, warnings: [__('New items aren\'t created by this feed.')]);
        }
        if (! $isNew && ! ($this->feed->sync()['update'] ?? true)) {
            return $this->row($sourceKey, RunItem::UNCHANGED, contentId: $this->destination->id($content));
        }

        if ($isNew && ($origin = $this->feed->get('localizes'))) {
            $this->context->origin = $this->origin((string) $origin, $key);
            if (! $this->context->origin) {
                return $this->row($sourceKey, RunItem::SKIPPED, warnings: [__('The item isn\'t in the :feed feed yet, so there\'s nothing to localize. It will be picked up on a later run.', ['feed' => $origin])]);
            }
        }

        $handles = array_keys(array_filter($this->feed->mapping(), fn ($m) => is_array($m) && $this->mapper->isMapped($m)));
        $current = $isNew ? [] : $this->destination->values($this->feed, $content, $handles);
        $this->context->current = $current;

        $mapped = $this->mapper->map($this->feed->mapping(), $this->fields, $item, $this->context);

        if ($mapped->errors !== []) {
            $messages = collect($mapped->errors)->map(fn ($m, $field) => "{$field}: {$m}")->values()->all();

            if (($this->feed->sync()['on_field_error'] ?? 'save_rest') === 'skip_item') {
                return $this->row($sourceKey, RunItem::FAILED, errors: $messages);
            }

            $warnings = array_merge($warnings, $messages);
        }

        $values = $mapped->values;
        $itemHash = Value::hash($values);
        $republish = $record && $record->state === 'unpublished';
        $force = ! empty($this->run->options['force']);

        if (! $isNew && $record && $record->itemHash === $itemHash && ! $republish && ! $force) {
            // Nothing to write: missing items are worked out from the run's
            // seen keys, so the map record needn't be touched.
            return $this->row($sourceKey, RunItem::UNCHANGED, contentId: $this->destination->id($content), warnings: array_merge($warnings, $this->context->warnings));
        }

        // Fields edited in the Control Panel since Ingest last wrote them.
        $conflicts = [];
        if (! $isNew && $record && ($this->feed->sync()['local_edits'] ?? 'overwrite') === 'keep') {
            foreach ($values as $handle => $value) {
                $written = $record->fieldHashes[$handle] ?? null;
                if ($written !== null && Value::hash($current[$handle] ?? null) !== $written && ! Value::same($current[$handle] ?? null, $value)) {
                    $conflicts[] = $handle;
                    unset($values[$handle]);
                }
            }
        }

        $changes = [];
        foreach ($values as $handle => $value) {
            if ($isNew || ! Value::same($current[$handle] ?? null, $value)) {
                $changes[$handle] = [$isNew ? null : ($current[$handle] ?? null), $value];
            }
        }

        if ($republish && $this->destination->isPublished($content) === false) {
            $changes['published'] = [false, true];
        }

        $warnings = array_merge($warnings, $this->context->warnings);
        foreach ($conflicts as $handle) {
            $warnings[] = __(':field was changed on the site since the last run, so the feed\'s value wasn\'t saved.', ['field' => $handle]);
        }

        if (! $isNew && $changes === []) {
            $this->touch($record ?? $this->newRecord($key, $sourceKey, $this->destination->id($content)), $itemHash, $values);

            return $this->row($sourceKey, $conflicts ? RunItem::CONFLICT : RunItem::UNCHANGED, contentId: $this->destination->id($content), warnings: $warnings);
        }

        $action = $isNew ? RunItem::CREATED : ($conflicts ? RunItem::CONFLICT : RunItem::UPDATED);
        $title = $this->titleFrom($values, $content);

        if ($this->run->dryRun) {
            return $this->row($sourceKey, $action, contentId: $isNew ? null : $this->destination->id($content), title: $title, changes: $changes, warnings: $warnings);
        }

        $saving = new ItemSaving($this->feed, $content, $values, $this->context);
        event($saving);
        if ($saving->cancel) {
            return $this->row($sourceKey, RunItem::SKIPPED, contentId: $isNew ? null : $this->destination->id($content), warnings: [__('Left as it is by an ItemSaving listener.')]);
        }
        $values = $saving->values;

        if ($this->hook) {
            $values = $this->hook->beforeSave($content, $values, $this->context);
        }

        $content ??= $this->destination->make($this->feed, $values, $this->context);
        $this->destination->fill($this->feed, $content, $values, $this->context);
        if ($republish) {
            $this->destination->setPublished($content, true);
        }
        $this->destination->save($content);

        if (method_exists($this->destination, 'afterSave')) {
            $this->destination->afterSave($this->feed, $content, $this->context);
        }

        $id = $this->destination->id($content);
        $record ??= $this->newRecord($key, $sourceKey, $id);
        $record->contentId = $id;
        $record->state = null;
        $record->missingSince = null;
        $this->touch($record, $itemHash, $values);

        (new ItemsFile(Ingest::storagePath('payloads/'.$this->run->id)))->markChanged($this->destination->contentType(), $id, $action);

        event(new ItemSaved($this->feed, $content, $action, $changes, $this->context));
        $this->hook?->afterSave($content, $this->context);

        return $this->row($sourceKey, $action, contentId: $id, title: $this->destination->title($content) ?? $title, changes: $changes, warnings: $warnings, snapshot: $this->context->created ? ['created' => $this->context->created] : null);
    }

    /**
     * Claim existing content that matches on a field, the first time a feed sees it.
     *
     * @return array{0: mixed, 1: ?string}
     */
    private function adopt(array $adopt, array $item): array
    {
        $field = (string) ($adopt['field'] ?? '');
        $value = Value::text($this->mapper->read((string) ($adopt['path'] ?? ''), $item, $this->context));

        if ($field === '' || $value === '') {
            return [null, null];
        }

        $matches = $this->destination->findBy($this->feed, $field, $value);

        if ($matches->count() > 1) {
            return [null, __('More than one existing item has :field ":value", so Ingest didn\'t guess which to use.', ['field' => $field, 'value' => $value])];
        }

        $content = $matches->first();

        if ($content && ($owner = $this->map->forContent($this->destination->id($content))) && $owner->feed !== $this->feed->handle()) {
            return [null, __('The existing item with :field ":value" belongs to the :feed feed.', ['field' => $field, 'value' => $value, 'feed' => $owner->feed])];
        }

        return [$content, null];
    }

    private function origin(string $feedHandle, string $key): mixed
    {
        $origin = $this->ingest->feed($feedHandle);
        $record = $origin ? $this->map->find($origin->handle(), $origin->site(), $key) : null;

        return $record ? $this->destinations->for($origin)->find($origin, $record->contentId) : null;
    }

    private function touch(MapRecord $record, ?string $itemHash = null, ?array $values = null): void
    {
        if ($this->run->dryRun) {
            return;
        }

        $record->lastSeenRunId = $this->run->id;

        if ($itemHash !== null) {
            $record->itemHash = $itemHash;
        }

        foreach ((array) $values as $handle => $value) {
            $record->fieldHashes[$handle] = Value::hash($value);
        }

        $this->map->put($record);
    }

    private function newRecord(string $key, string $sourceKey, string $contentId): MapRecord
    {
        return new MapRecord(
            feed: $this->feed->handle(),
            site: $this->feed->site(),
            key: $key,
            sourceKey: $sourceKey,
            contentType: $this->destination->contentType(),
            contentId: $contentId,
            firstRunId: $this->run->id,
        );
    }

    private function titleFrom(array $values, mixed $content): ?string
    {
        $title = $values['title'] ?? $values['name'] ?? $values['email'] ?? null;

        return $title !== null ? Value::text($title) : ($content ? $this->destination->title($content) : null);
    }

    private function row(string $key, string $action, ?string $contentId = null, ?string $title = null, array $changes = [], array $errors = [], array $warnings = [], ?array $snapshot = null): RunItem
    {
        return new RunItem(
            runId: $this->run->id,
            key: $key,
            action: $action,
            contentType: $this->destination->contentType(),
            contentId: $contentId,
            title: $title,
            changes: $changes,
            errors: $errors,
            warnings: array_values(array_unique($warnings)),
            snapshot: $snapshot,
            personal: $this->destination->personal(),
        );
    }
}
