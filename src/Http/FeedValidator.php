<?php

namespace NineteenNinetyFour\Ingest\Http;

use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use NineteenNinetyFour\Ingest\Contracts\FeedRepository;
use NineteenNinetyFour\Ingest\Destinations\Destinations;
use NineteenNinetyFour\Ingest\Feeds\Feed;
use NineteenNinetyFour\Ingest\Formats\Formats;
use NineteenNinetyFour\Ingest\Ingest;
use NineteenNinetyFour\Ingest\Schedule\Scheduler;
use NineteenNinetyFour\Ingest\Sources\GoogleSheetsSource;
use NineteenNinetyFour\Ingest\Sources\PathSource;
use NineteenNinetyFour\Ingest\Sources\Sources;
use NineteenNinetyFour\Ingest\Support\Filters;
use NineteenNinetyFour\Ingest\Support\Secrets;
use Throwable;

/**
 * Checks a feed from the Control Panel before it's saved. Errors are keyed
 * by setting (source.url, mapping.title…) so the form can show them in place.
 */
class FeedValidator
{
    /** Settings that are secrets: only env references are accepted. */
    private const SECRETS = ['source.auth.token', 'source.auth.password', 'source.auth.value', 'source.auth.client_secret'];

    public function __construct(
        private FeedRepository $feeds,
        private Sources $sources,
        private Formats $formats,
        private Destinations $destinations,
        private Ingest $ingest,
    ) {}

    /**
     * @throws ValidationException
     */
    public function validate(Feed $feed, bool $creating): void
    {
        $errors = [];
        $add = function (string $key, string $message) use (&$errors) {
            $errors[$key][] = $message;
        };

        if (trim((string) $feed->get('title')) === '') {
            $add('title', __('Give the feed a name.'));
        }

        if ($creating && $this->feeds->find($feed->handle())) {
            $add('handle', __('There\'s already a feed with this handle.'));
        }

        $this->source($feed, $add);
        $this->format($feed, $add);
        $this->destination($feed, $add);
        $this->sync($feed, $add);
        $this->schedule($feed, $add);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function source(Feed $feed, callable $add): void
    {
        $source = $feed->source();
        $type = (string) ($source['type'] ?? 'url');

        if (! array_key_exists($type === 'api' ? 'url' : $type, $this->sources->options())) {
            $add('source.type', __('Choose where the feed comes from.'));

            return;
        }

        if (in_array($type, ['url', 'api'], true)) {
            $url = trim((string) ($source['url'] ?? ''));
            if (! preg_match('#^https?://[^\s/$.?\#].[^\s]*$#i', $url)) {
                $add('source.url', __('Enter the feed\'s full web address, starting https://.'));
            }
        }

        if ($type === 'google_sheets') {
            try {
                GoogleSheetsSource::parse((string) ($source['url'] ?? ''));
            } catch (Throwable $e) {
                $add('source.url', $e->getMessage());
            }
        }

        if ($type === 'path') {
            try {
                PathSource::allowed((string) ($source['path'] ?? ''));
            } catch (Throwable $e) {
                $add('source.path', $e->getMessage());
            }
        }

        if ($type === 'upload' && empty($source['file'])) {
            $add('source.file', __('Upload a file.'));
        }

        foreach (self::SECRETS as $key) {
            $value = Arr::get($feed->toArray(), $key);
            if ($value !== null && $value !== '' && ! Secrets::isReference($value)) {
                $add($key, __('Secrets aren\'t saved with the feed. Put it in .env and enter env:NAME here.'));
            }
        }

        $auth = (string) ($source['auth']['type'] ?? 'none');
        if (! in_array($auth, ['none', 'bearer', 'basic', 'api_key', 'oauth2'], true)) {
            $add('source.auth.type', __('Unknown authentication type.'));
        }

        $pagination = (string) ($source['pagination']['type'] ?? 'none');
        if (! in_array($pagination, ['none', 'next_url', 'link_header', 'page', 'offset', 'cursor'], true)) {
            $add('source.pagination.type', __('Unknown pagination type.'));
        }
        if (in_array($pagination, ['next_url', 'cursor'], true) && empty($source['pagination']['path'])) {
            $add('source.pagination.path', __('Say where the next page\'s link or cursor is in the feed.'));
        }
    }

    private function format(Feed $feed, callable $add): void
    {
        $type = (string) ($feed->format()['type'] ?? 'auto');

        if ($type !== 'auto' && ! array_key_exists($type, $this->formats->options())) {
            $add('format.type', __('Unknown format.'));
        }
    }

    private function destination(Feed $feed, callable $add): void
    {
        try {
            $destination = $this->destinations->for($feed);
            $destination->validate($feed);
        } catch (Throwable $e) {
            $add('destination.type', $e->getMessage());

            return;
        }

        if ($feed->key() === [] && $feed->destination()['type'] !== 'globals') {
            $add('key', __('Choose which feed value identifies an item, such as an ID or SKU.'));
        }

        try {
            $fields = $destination->fields($feed);
        } catch (Throwable $e) {
            $add('destination.blueprint', $e->getMessage());

            return;
        }

        foreach ($feed->mapping() as $handle => $settings) {
            if (! $fields->has($handle)) {
                $add("mapping.{$handle}", __('The blueprint has no field called :field.', ['field' => $handle]));
            }
        }

        if ($localizes = $feed->get('localizes')) {
            if ($localizes === $feed->handle() || ! $this->feeds->find($localizes)) {
                $add('localizes', __('Choose another feed for this one to localize.'));
            }
        }
    }

    private function sync(Feed $feed, callable $add): void
    {
        $sync = $feed->sync();

        if (! in_array($sync['missing']['action'] ?? 'leave', ['leave', 'unpublish', 'delete'], true)) {
            $add('sync.missing.action', __('Choose what happens to items missing from the feed.'));
        }

        $max = $sync['safety']['max_removal_percent'] ?? null;
        if ($max !== null && $max !== '' && (! is_numeric($max) || $max < 0 || $max > 100)) {
            $add('sync.safety.max_removal_percent', __('A percentage from 0 to 100.'));
        }

        foreach ($feed->filters() as $i => $filter) {
            if (! in_array($filter['op'] ?? 'equals', Filters::OPERATORS, true)) {
                $add("filters.{$i}.op", __('Unknown filter.'));
            }
        }

        if ($hook = $feed->get('hook')) {
            if (! isset($this->ingest->hooks()[$hook])) {
                $add('hook', __('No hook is registered with that name.'));
            }
        }

        foreach ($feed->thenRun() as $handle) {
            if ($handle === $feed->handle() || ! $this->feeds->find($handle)) {
                $add('then_run', __('Choose other feeds to run afterwards.'));
            }
        }
    }

    private function schedule(Feed $feed, callable $add): void
    {
        $frequency = $feed->get('schedule.frequency', 'manual');

        if (! in_array($frequency, ['manual', 'minutes', 'hourly', 'daily', 'weekly', 'cron'], true)) {
            $add('schedule.frequency', __('Choose how often the feed runs.'));
        } elseif ($frequency !== 'manual' && Scheduler::expression($feed) === null) {
            $add('schedule.expression', __('That isn\'t a valid cron expression.'));
        }

        foreach (['daily', 'weekly'] as $f) {
            if ($frequency === $f && ! preg_match('/^\d{1,2}:\d{2}$/', (string) $feed->get('schedule.time', ''))) {
                $add('schedule.time', __('A time like 06:30.'));
            }
        }
    }
}
