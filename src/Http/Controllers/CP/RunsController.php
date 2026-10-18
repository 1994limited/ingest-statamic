<?php

namespace NineteenNinetyFour\Ingest\Http\Controllers\CP;

use Illuminate\Http\Request;
use Inertia\Inertia;
use NineteenNinetyFour\Ingest\Contracts\FeedRepository;
use NineteenNinetyFour\Ingest\Contracts\RunRepository;
use NineteenNinetyFour\Ingest\Http\Presenter;
use NineteenNinetyFour\Ingest\IngestException;
use NineteenNinetyFour\Ingest\Runs\AlreadyRunning;
use NineteenNinetyFour\Ingest\Runs\Run;
use NineteenNinetyFour\Ingest\Runs\Runner;
use NineteenNinetyFour\Ingest\Runs\Undoer;
use NineteenNinetyFour\Ingest\ServiceProvider;
use Statamic\Http\Controllers\CP\CpController;

class RunsController extends CpController
{
    /**
     * Start a run (or a dry run, or apply a dry run).
     */
    public function start(string $feed, Request $request, FeedRepository $feeds, Runner $runner, Presenter $presenter)
    {
        $this->authorize('run ingest feeds');
        $model = $feeds->find($feed) ?? abort(404);

        $request->validate([
            'dry' => ['boolean'],
            'force' => ['boolean'],
            'limit' => ['nullable', 'integer', 'min:1'],
            'apply' => ['nullable', 'string'],
        ]);

        try {
            $run = $runner->start($model, $request->input('apply') ? 'apply' : 'manual', $request->boolean('dry'), [
                'force' => $request->boolean('force'),
                'limit' => $request->input('limit') ? (int) $request->input('limit') : null,
                'apply' => $request->input('apply'),
            ], auth()->id());
        } catch (AlreadyRunning $e) {
            return response()->json(['message' => $e->getMessage(), 'run' => $e->run ? $presenter->run($e->run) : null], 409);
        } catch (IngestException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return ['run' => $presenter->run($run), 'redirect' => cp_route('ingest.runs.show', [$model->handle(), $run->id])];
    }

    public function show(string $feed, string $run, Request $request, FeedRepository $feeds, RunRepository $runs, Presenter $presenter)
    {
        $this->authorize('view ingest');
        $model = $feeds->find($feed) ?? abort(404);
        $record = $this->find($runs, $run, $feed);

        return Inertia::render('ingest::Run', [
            'feed' => ['handle' => $model->handle(), 'title' => $model->title(), 'url' => cp_route('ingest.feeds.show', $model->handle())],
            'run' => $presenter->run($record),
            'undoable' => $presenter->undoable($record),
            'can' => [
                'run' => (bool) auth()->user()?->can('run ingest feeds'),
                'undo' => (bool) auth()->user()?->can('undo ingest runs'),
            ],
            'icon' => ServiceProvider::ICON,
            'urls' => [
                'status' => cp_route('ingest.runs.status', $record->id),
                'items' => cp_route('ingest.runs.items', $record->id),
                'cancel' => cp_route('ingest.runs.cancel', $record->id),
                'undo' => cp_route('ingest.runs.undo', $record->id),
                'apply' => cp_route('ingest.feeds.run', $model->handle()),
            ],
        ]);
    }

    /**
     * Progress, polled while a run is going.
     */
    public function status(string $run, RunRepository $runs, Presenter $presenter)
    {
        $this->authorize('view ingest');

        $record = $this->find($runs, $run);

        return ['run' => $presenter->run($record), 'undoable' => $record->status->active() ? null : $presenter->undoable($record)];
    }

    public function items(string $run, Request $request, RunRepository $runs, Presenter $presenter)
    {
        $this->authorize('view ingest');
        $record = $this->find($runs, $run);

        $perPage = 50;
        $page = max(1, (int) $request->query('page', 1));
        $filters = array_filter([
            'action' => array_filter(explode(',', (string) $request->query('action', ''))),
            'search' => $request->query('search'),
        ]);

        // Warnings live on rows of any action.
        if (in_array('warnings', $filters['action'] ?? [], true)) {
            $all = $runs->allItems($record)->filter(fn ($i) => $i->warnings !== []);
            $result = ['items' => $all->slice(($page - 1) * $perPage, $perPage)->values(), 'total' => $all->count()];
        } else {
            $result = $runs->items($record, $filters, $perPage, ($page - 1) * $perPage);
        }

        return [
            'items' => collect($result['items'])->map(fn ($item) => $presenter->item($item))->values(),
            'total' => $result['total'],
            'page' => $page,
            'last_page' => max(1, (int) ceil($result['total'] / $perPage)),
        ];
    }

    public function cancel(string $run, RunRepository $runs, Runner $runner, Presenter $presenter)
    {
        $this->authorize('run ingest feeds');
        $record = $this->find($runs, $run);
        $runner->cancel($record);

        return ['run' => $presenter->run($this->find($runs, $run)), 'message' => __('Cancelling after the current batch.')];
    }

    public function undo(string $run, RunRepository $runs, Undoer $undoer, Presenter $presenter)
    {
        $this->authorize('undo ingest runs');
        $record = $this->find($runs, $run);

        try {
            $result = $undoer->undo($record);
        } catch (IngestException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return [
            'run' => $presenter->run($this->find($runs, $run)),
            'message' => $result['conflicts'] === []
                ? __('Undone.')
                : __('Undone, except :count changes made on the site since.', ['count' => count($result['conflicts'])]),
        ];
    }

    private function find(RunRepository $runs, string $id, ?string $feed = null): Run
    {
        $run = $runs->find($id);

        if (! $run || ($feed !== null && $run->feed !== $feed)) {
            abort(404);
        }

        return $run;
    }
}
