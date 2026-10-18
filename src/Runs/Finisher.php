<?php

namespace NineteenNinetyFour\Ingest\Runs;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use NineteenNinetyFour\Ingest\Contracts\FeedRepository;
use NineteenNinetyFour\Ingest\Contracts\RunRepository;
use NineteenNinetyFour\Ingest\Events\RunAborted;
use NineteenNinetyFour\Ingest\Events\RunCompleted;
use NineteenNinetyFour\Ingest\Feeds\Feed;
use NineteenNinetyFour\Ingest\Ingest;
use NineteenNinetyFour\Ingest\Notifications\Notifier;
use NineteenNinetyFour\Ingest\Sources\PathSource;
use Statamic\Contracts\Search\Searchable;
use Statamic\Facades\Entry;
use Statamic\Facades\Git;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Search;
use Statamic\Facades\Term;
use Statamic\Facades\User;
use Statamic\StaticCaching\Invalidator;
use Throwable;

/**
 * The end of a run: catch up on what quiet saves skipped (search indexes,
 * the static cache, one Git commit for the whole run), set the final
 * status, notify, and start chained feeds.
 */
class Finisher
{
    public function __construct(
        private RunRepository $runs,
        private FeedRepository $feeds,
        private Notifier $notifier,
    ) {}

    public function finish(Feed $feed, Run $run): void
    {
        $this->settle($feed, $run);

        if ($run->status !== RunStatus::Aborted) {
            $run->status = $run->counts['failed'] > 0 ? RunStatus::CompletedWithErrors : RunStatus::Completed;
        }

        $run->finishedAt = CarbonImmutable::now();
        $this->runs->save($run);

        event($run->status === RunStatus::Aborted ? new RunAborted($feed, $run) : new RunCompleted($feed, $run));

        if (! $run->dryRun && $run->status->successful() && ($path = $run->payload['validators']['source_path'] ?? null) && ! empty($feed->source()['archive'])) {
            PathSource::archive($path);
        }

        $this->afterRun($feed, $run);

        if (! $run->dryRun && $run->status->successful()) {
            $this->chain($feed, $run);
        }
    }

    /**
     * Bring search, the static cache and Git up to date with what a run
     * changed, however it ended (finished, failed partway, cancelled).
     */
    public function settle(Feed $feed, Run $run): void
    {
        if ($run->dryRun || ! config('ingest.quiet_saves', true)) {
            return;
        }

        $items = new ItemsFile(Ingest::storagePath('payloads/'.$run->id));
        $this->catchUp($items->changed());
        $this->commit($feed, $run);
    }

    /**
     * Notifications and tidying, for every finished run (failed ones too).
     */
    public function afterRun(Feed $feed, Run $run): void
    {
        try {
            $this->notifier->runFinished($feed, $run);
        } catch (Throwable $e) {
            report($e);
        }

        // Dry runs keep their items so they can be applied; real runs don't need them.
        if (! $run->dryRun) {
            File::deleteDirectory(Ingest::storagePath('payloads/'.$run->id));
        }
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: string}>  $changed  [type, id, action]
     */
    private function catchUp(array $changed): void
    {
        $invalidator = app(Invalidator::class);
        $done = [];

        foreach ($changed as [$type, $id, $action]) {
            if ($action === RunItem::DELETED || isset($done[$type.$id])) {
                continue;
            }
            $done[$type.$id] = true;

            try {
                $content = match ($type) {
                    'entry' => Entry::find($id),
                    'term' => Term::find($id),
                    'user' => User::find($id),
                    'global' => GlobalSet::find(explode('::', $id)[0])?->in(explode('::', $id)[1] ?? null),
                    default => null,
                };

                if (! $content) {
                    continue;
                }

                if ($content instanceof Searchable) {
                    Search::updateWithinIndexes($content);
                }

                $invalidator->invalidate($content);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    private function commit(Feed $feed, Run $run): void
    {
        if (! config('statamic.git.enabled') || $run->changed() === 0) {
            return;
        }

        try {
            Git::dispatchCommit(sprintf(
                'Ingest: %s — %d created, %d updated, %d unpublished, %d deleted',
                $feed->title(),
                $run->counts['created'],
                $run->counts['updated'],
                $run->counts['unpublished'],
                $run->counts['deleted'],
            ));
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function chain(Feed $feed, Run $run): void
    {
        foreach ($feed->thenRun() as $handle) {
            if ($handle === $feed->handle() || ! ($next = $this->feeds->find($handle))) {
                continue;
            }

            try {
                app(Runner::class)->start($next, 'chain');
            } catch (Throwable $e) {
                $run->payload['notes'][] = __('Couldn\'t start :feed: :message', ['feed' => $next->title(), 'message' => $e->getMessage()]);
                $this->runs->save($run);
            }
        }
    }
}
