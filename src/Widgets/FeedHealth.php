<?php

namespace NineteenNinetyFour\Ingest\Widgets;

use NineteenNinetyFour\Ingest\Contracts\FeedRepository;
use NineteenNinetyFour\Ingest\Http\Presenter;
use NineteenNinetyFour\Ingest\Schedule\Scheduler;
use Statamic\Widgets\VueComponent;
use Statamic\Widgets\Widget;

/**
 * Dashboard: feeds that need attention first (failed, stopped by the
 * safety check, or not run lately), then what's due next.
 *
 *     widgets:
 *       - type: ingest_feed_health
 *         width: 50
 */
class FeedHealth extends Widget
{
    protected static $handle = 'ingest_feed_health';

    public function component()
    {
        if (! auth()->user()?->can('view ingest')) {
            return null;
        }

        $presenter = app(Presenter::class);
        $feeds = app(FeedRepository::class)->all()->map(fn ($feed) => $presenter->feed($feed))->values();

        $attention = $feeds->filter(fn ($f) => in_array($f['last_run']['status'] ?? null, ['failed', 'aborted', 'completed_with_errors'], true))->values();

        return VueComponent::render('ingest-feed-health', [
            'title' => $this->config('title', __('Feeds')),
            'feeds' => $feeds->sortBy(fn ($f) => $f['next_run'] ?? '9999')->take((int) $this->config('limit', 6))->values(),
            'attention' => $attention,
            'schedulerRunning' => Scheduler::running(),
            'scheduled' => $feeds->contains(fn ($f) => $f['enabled'] && $f['next_run']),
            'url' => cp_route('ingest.index'),
        ]);
    }
}
