<?php

namespace NineteenNinetyFour\Ingest\Http\Controllers\CP;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Inertia\Inertia;
use NineteenNinetyFour\Ingest\Contracts\FeedRepository;
use NineteenNinetyFour\Ingest\Contracts\ItemMap;
use NineteenNinetyFour\Ingest\Contracts\RunRepository;
use NineteenNinetyFour\Ingest\Feeds\Feed;
use NineteenNinetyFour\Ingest\Http\FeedValidator;
use NineteenNinetyFour\Ingest\Http\Presenter;
use NineteenNinetyFour\Ingest\Schedule\Scheduler;
use NineteenNinetyFour\Ingest\ServiceProvider;
use NineteenNinetyFour\Ingest\Sources\FileSource;
use NineteenNinetyFour\Ingest\Webhooks\Token;
use Statamic\Facades\YAML;
use Statamic\Http\Controllers\CP\CpController;

class FeedsController extends CpController
{
    public function index(FeedRepository $feeds, Presenter $presenter)
    {
        $this->authorize('view ingest');

        $list = $feeds->all()->map(fn (Feed $feed) => $presenter->feed($feed))->values();

        return Inertia::render('ingest::Index', [
            'feeds' => $list,
            'schedulerRunning' => Scheduler::running(),
            'scheduled' => $feeds->all()->contains(fn (Feed $f) => $f->enabled() && Scheduler::expression($f) !== null),
            'can' => $this->can(),
            'icon' => ServiceProvider::ICON,
            'urls' => [
                'create' => cp_route('ingest.feeds.create'),
                'settings' => cp_route('addons.settings.edit', 'ingest-statamic'),
            ],
        ]);
    }

    public function create()
    {
        $this->authorize('manage ingest feeds');

        return Inertia::render('ingest::Feed', $this->formProps(null));
    }

    public function store(Request $request, FeedRepository $feeds, FeedValidator $validator)
    {
        $this->authorize('manage ingest feeds');

        $handle = Str::slug((string) ($request->input('handle') ?: $request->input('feed.title')), '_');
        $request->merge(['handle' => $handle]);
        $request->validate(['handle' => ['required', 'regex:/^[a-z0-9][a-z0-9_-]*$/', 'max:64']]);

        $feed = Feed::make($handle, $this->clean((array) $request->input('feed', [])));
        $validator->validate($feed, true);
        $feeds->save($feed);

        return ['redirect' => cp_route('ingest.feeds.show', $handle), 'message' => __('Feed saved.')];
    }

    public function show(string $feed, FeedRepository $feeds, RunRepository $runs, Presenter $presenter, Request $request)
    {
        $this->authorize('view ingest');
        $model = $this->find($feeds, $feed);

        $page = max(1, (int) $request->query('page', 1));
        $perPage = 25;

        return Inertia::render('ingest::Show', [
            'feed' => $presenter->feed($model, true),
            'runs' => $runs->forFeed($model->handle(), $perPage, ($page - 1) * $perPage)->map(fn ($r) => $presenter->run($r))->values(),
            'pagination' => ['page' => $page, 'last_page' => max(1, (int) ceil($runs->countForFeed($model->handle()) / $perPage))],
            'webhookUrl' => Token::enabled($model) ? url(config('statamic.routes.action', '!').'/ingest/feeds/'.$model->handle().'/run') : null,
            'can' => $this->can(),
            'icon' => ServiceProvider::ICON,
            'urls' => [
                'index' => cp_route('ingest.index'),
                'edit' => cp_route('ingest.feeds.edit', $model->handle()),
                'run' => cp_route('ingest.feeds.run', $model->handle()),
                'duplicate' => cp_route('ingest.feeds.duplicate', $model->handle()),
                'toggle' => cp_route('ingest.feeds.toggle', $model->handle()),
                'destroy' => cp_route('ingest.feeds.destroy', $model->handle()),
                'webhook' => cp_route('ingest.feeds.webhook', $model->handle()),
                'download' => cp_route('ingest.feeds.download', $model->handle()),
                'self' => cp_route('ingest.feeds.show', $model->handle()),
            ],
        ]);
    }

    public function edit(string $feed, FeedRepository $feeds)
    {
        $this->authorize('manage ingest feeds');

        return Inertia::render('ingest::Feed', $this->formProps($this->find($feeds, $feed)));
    }

    public function update(string $feed, Request $request, FeedRepository $feeds, FeedValidator $validator)
    {
        $this->authorize('manage ingest feeds');
        $existing = $this->find($feeds, $feed);

        $data = $this->clean((array) $request->input('feed', []));
        // The webhook token is managed on its own, never by the form.
        $data['webhook'] = $existing->get('webhook');

        $model = Feed::make($existing->handle(), $data);
        $validator->validate($model, false);
        $feeds->save($model);

        return ['redirect' => cp_route('ingest.feeds.show', $model->handle()), 'message' => __('Feed saved.')];
    }

    public function destroy(string $feed, Request $request, FeedRepository $feeds, ItemMap $map)
    {
        $this->authorize('manage ingest feeds');
        $model = $this->find($feeds, $feed);

        $feeds->delete($model);
        // Content the feed made stays; Ingest just forgets it owned it.
        $map->forget($model->handle());

        return ['redirect' => cp_route('ingest.index'), 'message' => __('Feed deleted. Its content was kept.')];
    }

    public function duplicate(string $feed, FeedRepository $feeds)
    {
        $this->authorize('manage ingest feeds');
        $model = $this->find($feeds, $feed);

        $handle = $model->handle().'_copy';
        for ($i = 2; $feeds->find($handle); $i++) {
            $handle = $model->handle().'_copy_'.$i;
        }

        $copy = Feed::make($handle, $model->toArray());
        $copy->set('title', __(':title (copy)', ['title' => $model->title()]));
        $copy->set('enabled', false);
        // Never the same webhook token.
        Token::revoke($copy);
        $feeds->save($copy);

        return ['redirect' => cp_route('ingest.feeds.edit', $handle), 'message' => __('Copied. The copy is paused until you turn it on.')];
    }

    public function toggle(string $feed, FeedRepository $feeds)
    {
        $this->authorize('manage ingest feeds');
        $model = $this->find($feeds, $feed);
        $model->set('enabled', ! $model->enabled());
        $feeds->save($model);

        return ['enabled' => $model->enabled(), 'message' => $model->enabled() ? __('Feed turned on.') : __('Feed paused.')];
    }

    public function webhook(string $feed, Request $request, FeedRepository $feeds)
    {
        $this->authorize('manage ingest feeds');
        $model = $this->find($feeds, $feed);

        if ($request->isMethod('delete')) {
            Token::revoke($model);
            $feeds->save($model);

            return ['token' => null, 'message' => __('Webhook turned off.')];
        }

        $token = Token::issue($model);
        $feeds->save($model);

        return [
            'token' => $token,
            'url' => url(config('statamic.routes.action', '!').'/ingest/feeds/'.$model->handle().'/run'),
            'message' => __('Copy the token now: it won\'t be shown again.'),
        ];
    }

    public function upload(Request $request)
    {
        $this->authorize('manage ingest feeds');

        $request->validate([
            'handle' => ['required', 'regex:/^[a-z0-9][a-z0-9_-]*$/'],
            'file' => ['required', 'file', 'max:'.((int) config('ingest.http.max_feed_size', 200) * 1024), 'extensions:json,ndjson,jsonl,xml,rss,atom,csv,tsv,txt,blm'],
        ]);

        $file = $request->file('file');
        $name = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)).'.'.strtolower($file->getClientOriginalExtension());
        File::ensureDirectoryExists(FileSource::directory($request->input('handle')));
        $file->move(FileSource::directory($request->input('handle')), $name);

        return ['file' => $name];
    }

    public function download(string $feed, FeedRepository $feeds)
    {
        $this->authorize('manage ingest feeds');
        $model = $this->find($feeds, $feed);

        $data = $model->fileData();
        unset($data['webhook']);

        return response(YAML::dump($data), 200, [
            'Content-Type' => 'application/x-yaml',
            'Content-Disposition' => 'attachment; filename="'.$model->handle().'.yaml"',
        ]);
    }

    private function formProps(?Feed $feed): array
    {
        return [
            'handle' => $feed?->handle(),
            'feed' => $feed ? $this->forForm($feed) : Feed::make('new')->toArray(),
            'icon' => ServiceProvider::ICON,
            'urls' => [
                'index' => cp_route('ingest.index'),
                'save' => $feed ? cp_route('ingest.feeds.update', $feed->handle()) : cp_route('ingest.feeds.store'),
                'preview' => cp_route('ingest.preview'),
                'fields' => cp_route('ingest.meta.fields'),
                'options' => cp_route('ingest.meta.options'),
                'upload' => cp_route('ingest.feeds.upload'),
                'show' => $feed ? cp_route('ingest.feeds.show', $feed->handle()) : null,
            ],
        ];
    }

    private function forForm(Feed $feed): array
    {
        $data = $feed->toArray();
        unset($data['webhook']);

        // Lists the form edits as rows.
        $headers = (array) ($data['source']['headers'] ?? []);
        $data['source']['headers'] = array_is_list($headers) ? $headers : collect($headers)->map(fn ($v, $k) => ['name' => $k, 'value' => $v])->values()->all();
        $query = (array) ($data['source']['query'] ?? []);
        $data['source']['query'] = array_is_list($query) ? $query : collect($query)->map(fn ($v, $k) => ['name' => $k, 'value' => $v])->values()->all();

        return $data;
    }

    /**
     * Tidy what the form sends: rows back to maps, empty mappings out.
     */
    private function clean(array $data): array
    {
        unset($data['webhook']);

        foreach (['headers', 'query'] as $list) {
            $rows = (array) ($data['source'][$list] ?? []);
            $data['source'][$list] = collect($rows)
                ->filter(fn ($row) => is_array($row) && trim((string) ($row['name'] ?? '')) !== '')
                ->mapWithKeys(fn ($row) => [trim((string) $row['name']) => (string) ($row['value'] ?? '')])
                ->all();
        }

        // Defaults aren't written out, so files stay short.
        foreach (['headers', 'query'] as $list) {
            if (($data['source'][$list] ?? []) === []) {
                unset($data['source'][$list]);
            }
        }
        if (($data['source']['auth']['type'] ?? 'none') === 'none') {
            unset($data['source']['auth']);
        }
        if (($data['source']['pagination']['type'] ?? 'none') === 'none') {
            unset($data['source']['pagination']);
        }

        $data['mapping'] = collect((array) ($data['mapping'] ?? []))
            ->filter(fn ($m) => is_array($m) && (
                ($m['path'] ?? '') !== '' || ($m['template'] ?? '') !== '' || array_key_exists('value', $m) || ! empty($m['fields']) || ! empty($m['sets'])
            ))
            ->all();

        $data['key'] = array_values(array_filter((array) ($data['key'] ?? []), fn ($k) => is_string($k) && trim($k) !== ''));
        $data['filters'] = array_values(array_filter((array) ($data['filters'] ?? []), fn ($f) => is_array($f) && ($f['path'] ?? '') !== ''));

        return $data;
    }

    private function find(FeedRepository $feeds, string $handle): Feed
    {
        return $feeds->find($handle) ?? abort(404);
    }

    private function can(): array
    {
        $user = auth()->user();

        return [
            'run' => (bool) $user?->can('run ingest feeds'),
            'manage' => (bool) $user?->can('manage ingest feeds'),
            'undo' => (bool) $user?->can('undo ingest runs'),
        ];
    }
}
