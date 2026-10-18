<?php

namespace NineteenNinetyFour\Ingest\Http\Controllers\CP;

use DuncanMcClean\Cargo\Facades\TaxClass;
use Illuminate\Http\Request;
use NineteenNinetyFour\Ingest\Contracts\FeedRepository;
use NineteenNinetyFour\Ingest\Destinations\Destinations;
use NineteenNinetyFour\Ingest\Feeds\Feed;
use NineteenNinetyFour\Ingest\Formats\Formats;
use NineteenNinetyFour\Ingest\Http\FieldSchema;
use NineteenNinetyFour\Ingest\Ingest;
use NineteenNinetyFour\Ingest\Integrations\Cargo\Cargo;
use NineteenNinetyFour\Ingest\Mapping\Transforms;
use NineteenNinetyFour\Ingest\Sources\Sources;
use NineteenNinetyFour\Ingest\Support\Filters;
use NineteenNinetyFour\Ingest\Support\Secrets;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Collection;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Role;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Http\Controllers\CP\CpController;
use Throwable;

/**
 * What the feed form needs to offer: collections and blueprints,
 * taxonomies, global sets, sites, other feeds, transforms…
 */
class MetaController extends CpController
{
    public function options(FeedRepository $feeds, Sources $sources, Formats $formats, Destinations $destinations, Transforms $transforms, Ingest $ingest, Request $request)
    {
        $this->authorize('manage ingest feeds');

        $env = collect(explode(',', (string) $request->query('env', '')))->filter()->map(fn ($name) => ['name' => $name, 'set' => Secrets::exists($name)])->values();

        return [
            'sources' => $this->list($sources->options()),
            'formats' => $this->list(['auto' => __('Detect')] + $formats->options()),
            'destinations' => $this->list($destinations->options()),
            'collections' => Collection::all()->map(fn ($c) => [
                'value' => $c->handle(),
                'label' => $c->title(),
                'blueprints' => $c->entryBlueprints()->map(fn ($b) => ['value' => $b->handle(), 'label' => $b->title()])->values(),
                'cargo' => Cargo::installed() && in_array($c->handle(), Cargo::productCollections(), true),
                'dated' => $c->dated(),
                'structured' => $c->hasStructure(),
            ])->values(),
            'taxonomies' => Taxonomy::all()->map(fn ($t) => [
                'value' => $t->handle(),
                'label' => $t->title(),
                'blueprints' => $t->termBlueprints()->map(fn ($b) => ['value' => $b->handle(), 'label' => $b->title()])->values(),
            ])->values(),
            'globals' => GlobalSet::all()->map(fn ($g) => ['value' => $g->handle(), 'label' => $g->title()])->values(),
            'sites' => Site::all()->map(fn ($s) => ['value' => $s->handle(), 'label' => $s->name()])->values(),
            'containers' => AssetContainer::all()->map(fn ($c) => ['value' => $c->handle(), 'label' => $c->title()])->values(),
            'feeds' => $feeds->all()->map(fn (Feed $f) => ['value' => $f->handle(), 'label' => $f->title()])->values(),
            'hooks' => collect(array_keys($ingest->hooks()))->map(fn ($h) => ['value' => $h, 'label' => $h])->values(),
            'transforms' => $transforms->names(),
            'modifiers' => Transforms::MODIFIERS,
            'operators' => Filters::OPERATORS,
            'roles' => collect((array) config('ingest.users.roles', []))->map(fn ($r) => ['value' => $r, 'label' => Role::find($r)?->title() ?? $r])->values(),
            'tax_classes' => $this->taxClasses(),
            'env' => $env,
            'cargo' => Cargo::installed(),
            'multisite' => Site::multiEnabled(),
        ];
    }

    /**
     * The fields a destination offers for mapping.
     */
    public function fields(Request $request, Destinations $destinations, FieldSchema $schema)
    {
        $this->authorize('manage ingest feeds');

        try {
            $feed = Feed::make('preview', ['destination' => (array) $request->input('destination', [])]);
            $fields = $destinations->for($feed)->fields($feed);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return ['fields' => $schema->describe($fields)];
    }

    private function list(array $options): array
    {
        return collect($options)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values()->all();
    }

    private function taxClasses(): array
    {
        if (! Cargo::installed()) {
            return [];
        }

        try {
            return TaxClass::all()->map(fn ($t) => ['value' => $t->handle(), 'label' => $t->get('name') ?? $t->handle()])->values()->all();
        } catch (Throwable) {
            return [];
        }
    }
}
