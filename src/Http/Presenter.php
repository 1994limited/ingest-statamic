<?php

namespace NineteenNinetyFour\Ingest\Http;

use NineteenNinetyFour\Ingest\Contracts\ItemMap;
use NineteenNinetyFour\Ingest\Contracts\RunRepository;
use NineteenNinetyFour\Ingest\Destinations\Destinations;
use NineteenNinetyFour\Ingest\Feeds\Feed;
use NineteenNinetyFour\Ingest\Runs\Run;
use NineteenNinetyFour\Ingest\Runs\RunItem;
use NineteenNinetyFour\Ingest\Runs\Undoer;
use NineteenNinetyFour\Ingest\Schedule\Scheduler;
use NineteenNinetyFour\Ingest\Support\Html;
use NineteenNinetyFour\Ingest\Support\Path;
use NineteenNinetyFour\Ingest\Support\Secrets;
use NineteenNinetyFour\Ingest\Webhooks\Token;
use Statamic\Facades\Entry;
use Statamic\Facades\Term;
use Statamic\Facades\User;
use Throwable;

/**
 * Feeds, runs and run items as the Control Panel shows them.
 */
class Presenter
{
    public function __construct(
        private RunRepository $runs,
        private ItemMap $map,
        private Destinations $destinations,
        private Undoer $undoer,
    ) {}

    public function feed(Feed $feed, bool $withCounts = false): array
    {
        $last = $this->runs->forFeed($feed->handle(), 1)->first();
        $lastReal = $last && $last->dryRun ? $this->runs->forFeed($feed->handle(), 10)->first(fn (Run $r) => ! $r->dryRun) : $last;
        $active = $this->runs->active($feed->handle());

        return [
            'handle' => $feed->handle(),
            'title' => $feed->title(),
            'enabled' => $feed->enabled(),
            'source' => $this->source($feed),
            'destination' => $this->destination($feed),
            'schedule' => $this->schedule($feed),
            'next_run' => Scheduler::next($feed)?->toIso8601String(),
            'last_run' => $lastReal ? $this->run($lastReal) : null,
            'active_run' => $active ? $this->run($active) : null,
            'items' => $withCounts ? $this->map->count($feed->handle()) : null,
            'webhook' => Token::enabled($feed),
            'urls' => [
                'show' => cp_route('ingest.feeds.show', $feed->handle()),
                'edit' => cp_route('ingest.feeds.edit', $feed->handle()),
                'run' => cp_route('ingest.feeds.run', $feed->handle()),
            ],
        ];
    }

    public function run(Run $run): array
    {
        return [
            'id' => $run->id,
            'feed' => $run->feed,
            'trigger' => $run->trigger,
            'dry_run' => $run->dryRun,
            'status' => $run->status->value,
            'status_label' => $run->status->label(),
            'active' => $run->status->active(),
            'counts' => $run->counts,
            'changed' => $run->changed(),
            'total' => $run->total,
            'processed' => $run->processed,
            'message' => $run->message,
            'notes' => array_values((array) ($run->payload['notes'] ?? [])),
            'warnings' => array_values((array) ($run->payload['warnings'] ?? [])),
            'unchanged_feed' => (bool) ($run->payload['unchanged'] ?? false),
            'applied_from' => $run->payload['applied_from'] ?? null,
            'undo_conflicts' => array_values((array) ($run->payload['undo_conflicts'] ?? [])),
            'user' => $run->user ? (User::find($run->user)?->name() ?? null) : null,
            'created_at' => $run->createdAt?->toIso8601String(),
            'started_at' => $run->startedAt?->toIso8601String(),
            'finished_at' => $run->finishedAt?->toIso8601String(),
            'duration' => $run->duration(),
            'url' => cp_route('ingest.runs.show', [$run->feed, $run->id]),
        ];
    }

    public function undoable(Run $run): ?string
    {
        return $this->undoer->canUndo($run);
    }

    public function item(RunItem $item): array
    {
        return [
            'key' => $item->key,
            'action' => $item->action,
            'title' => $item->title,
            'content_id' => $item->contentId,
            'edit_url' => $this->editUrl($item),
            'changes' => collect($item->changes)->map(fn ($change, $field) => [
                'field' => $field,
                'old' => $item->personal && ! $item->contentId ? null : self::show($change[0] ?? null),
                'new' => self::show($change[1] ?? null),
            ])->values()->all(),
            'errors' => $item->errors,
            'warnings' => $item->warnings,
            'position' => $item->position,
        ];
    }

    /**
     * A value short enough for a table cell.
     */
    public static function show(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? __('Yes') : __('No');
        }

        if (is_array($value)) {
            // Bard: its text.
            if (Path::isList($value) && isset($value[0]['type']) && in_array($value[0]['type'], ['paragraph', 'heading', 'set', 'bulletList'], true)) {
                $value = self::bardText($value);
            } else {
                $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }

        $text = Html::looksLikeHtml((string) $value) ? Html::text((string) $value) : (string) $value;

        return mb_strlen($text) > 240 ? mb_substr($text, 0, 240).'…' : $text;
    }

    private static function bardText(array $nodes): string
    {
        $text = '';
        array_walk_recursive($nodes, function ($value, $key) use (&$text) {
            if ($key === 'text') {
                $text .= $value.' ';
            }
        });

        return trim($text);
    }

    private function editUrl(RunItem $item): ?string
    {
        if (! $item->contentId || $item->action === RunItem::DELETED) {
            return null;
        }

        try {
            return match ($item->contentType) {
                'entry' => Entry::find($item->contentId)?->editUrl(),
                'term' => Term::find($item->contentId)?->editUrl(),
                'user' => User::find($item->contentId)?->editUrl(),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }

    private function source(Feed $feed): array
    {
        $source = $feed->source();
        $type = (string) ($source['type'] ?? 'url');

        return [
            'type' => $type,
            'label' => match ($type) {
                'url', 'api' => parse_url((string) ($source['url'] ?? ''), PHP_URL_HOST) ?: __('URL'),
                'upload' => $source['file'] ?? __('Uploaded file'),
                'path' => basename((string) ($source['path'] ?? '')),
                'google_sheets' => __('Google Sheet'),
                default => $type,
            },
            'format' => $feed->format()['type'] ?? 'auto',
            'env' => $this->env($feed),
        ];
    }

    /**
     * The env references a feed uses, and whether each is set. Never values.
     *
     * @return array<int, array{name: string, set: bool}>
     */
    public function env(Feed $feed): array
    {
        $names = [];
        $source = $feed->source();
        array_walk_recursive($source, function ($value) use (&$names) {
            if (Secrets::isReference($value)) {
                $names[] = Secrets::name($value);
            }
        });

        return collect(array_unique($names))->map(fn ($name) => ['name' => $name, 'set' => Secrets::exists($name)])->values()->all();
    }

    private function destination(Feed $feed): array
    {
        $d = $feed->destination();

        try {
            $label = $this->destinations->for($feed)::label();
        } catch (Throwable) {
            $label = $d['type'] ?? '';
        }

        return [
            'type' => $d['type'] ?? 'entries',
            'label' => $label,
            'target' => $d['collection'] ?? $d['taxonomy'] ?? $d['set'] ?? null,
            'site' => $feed->site(),
        ];
    }

    private function schedule(Feed $feed): string
    {
        $s = (array) $feed->get('schedule', []);

        return match ($s['frequency'] ?? 'manual') {
            'minutes' => __('Every :n minutes', ['n' => $s['every'] ?? 15]),
            'hourly' => __('Hourly at :m past', ['m' => str_pad((string) ($s['minute'] ?? 0), 2, '0', STR_PAD_LEFT)]),
            'daily' => __('Daily at :time', ['time' => $s['time'] ?? '06:00']),
            'weekly' => __('Weekly, :day at :time', ['day' => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][(int) ($s['day'] ?? 1) % 7], 'time' => $s['time'] ?? '06:00']),
            'cron' => (string) ($s['expression'] ?? ''),
            default => __('Manual'),
        };
    }
}
