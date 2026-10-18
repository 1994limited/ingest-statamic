# PHP API, hooks and events

Everything below goes in a service provider's `boot()` method, usually `App\Providers\AppServiceProvider`.

## Running feeds

```php
use NineteenNinetyFour\Ingest\Facades\Ingest;

$run = Ingest::run('supplier');                    // queue a run
$run = Ingest::run('supplier', dryRun: true);
$run = Ingest::run('supplier', options: ['limit' => 10, 'force' => true]);

$run->id;
$run->status;       // NineteenNinetyFour\Ingest\Runs\RunStatus
$run->counts;       // ['created' => 3, 'updated' => 41, …]

Ingest::feed('supplier')?->title();
Ingest::feeds()->all();
```

`run()` throws `AlreadyRunning` if the feed is running, and `IngestException` with a readable message for anything a person can fix.

## Hooks

A hook is PHP for one feed: change items as they're read, change values before they're saved, or act after. Hooks live in your app; nothing typed in the Control Panel can run PHP.

```php
use NineteenNinetyFour\Ingest\Hooks\FeedHook;
use NineteenNinetyFour\Ingest\Mapping\Context;

class SupplierHook extends FeedHook
{
    public function item(array $item, Context $context): ?array
    {
        // Skip the supplier's samples.
        if (str_starts_with($item['sku'], 'SAMPLE-')) {
            return null;
        }

        $item['title'] = Str::title($item['title']);

        return $item;
    }

    public function beforeSave(mixed $content, array $values, Context $context): array
    {
        $values['in_stock'] = ($values['stock'] ?? 0) > 0;

        return $values;
    }

    public function afterSave(mixed $content, Context $context): void
    {
        // $content is the saved entry. Not called on dry runs.
    }
}
```

```php
Ingest::hook('supplier', SupplierHook::class);
```

Choose it under **Sync rules → PHP hook**, or `hook: supplier` in the feed's YAML. `$context->item` is the feed item, `$context->isNew` says whether the item is being created, `$context->dryRun` whether this is a dry run, and `$context->warn('…')` adds a warning to the run.

## Transforms

```php
Ingest::transform('add_vat', function ($value, array $args, Context $context) {
    return round((float) $value * (1 + ($args[0] ?? 20) / 100), 2);
});
```

```yaml
price:
  path: net_price
  transforms:
    - { name: add_vat, args: [20] }
```

Throw `NineteenNinetyFour\Ingest\Mapping\FieldError` to fail the field with a message.

## Events

| Event | When | You can |
|---|---|---|
| `RunStarting` | Before a run is queued | Set `$cancel` (and `$reason`) |
| `FeedFetched` | The feed has been read; `$run->total` is known | |
| `ItemParsed` | An item has been read | Change `$item`, or set `$skip` |
| `ItemSaving` | Before an item is saved (`$content` is null when new) | Change `$values`, or set `$cancel` |
| `ItemSaved` | After (`$action` is `created` or `updated`; `$changes` lists old and new values) | |
| `ItemFailed` | An item failed | |
| `MissingItemsResolving` | Before items are unpublished or deleted | Set `$cancel` |
| `RunCompleted` | A run finished (perhaps with item errors) | |
| `RunAborted` | The safety check stopped the missing-items step | |
| `RunFailed` | A run couldn't finish; `$run->message` says why | |

All are in `NineteenNinetyFour\Ingest\Events`.

```php
use Illuminate\Support\Facades\Event;
use NineteenNinetyFour\Ingest\Events\ItemSaved;

Event::listen(function (ItemSaved $event) {
    if ($event->action === 'created' && $event->feed->handle() === 'jobs') {
        // Post the new job to the staff channel.
    }
});
```

With `quiet_saves` on (the default), Statamic's own `EntrySaved` events aren't fired for each item; listen for `ItemSaved` instead, or turn `quiet_saves` off.

## Your own source

```php
use NineteenNinetyFour\Ingest\Contracts\Source;
use NineteenNinetyFour\Ingest\Feeds\Feed;
use NineteenNinetyFour\Ingest\Sources\Payload;

class SftpSource implements Source
{
    public static function handle(): string { return 'sftp'; }
    public static function label(): string { return 'SFTP'; }

    public function fetch(Feed $feed, string $directory, array $previous = []): Payload
    {
        $contents = Storage::disk('supplier_sftp')->get($feed->source()['path']);
        file_put_contents($directory.'/page-1', $contents);

        return new Payload([['path' => 'page-1', 'url' => null, 'content_type' => null, 'extension' => 'csv']]);
    }
}

Ingest::source(SftpSource::class);
```

Settings under the feed's `source:` reach `fetch()` through `$feed->source()`.

## Your own format

Implement `NineteenNinetyFour\Ingest\Contracts\Format` (`items()` yields each item as an array; `sniff()` scores how likely a file is yours) and register it with `Ingest::format(MyFormat::class)`.

## Your own destination

Implement `NineteenNinetyFour\Ingest\Contracts\Destination` (find, make, fill, save, delete, snapshot and restore content; list the fields a mapping can fill) and register it with `Ingest::destination(MyDestination::class)`. Runway models are a good candidate.

## Your own field mapper

To handle a fieldtype Ingest doesn't know:

```php
use NineteenNinetyFour\Ingest\Mapping\Context;
use NineteenNinetyFour\Ingest\Mapping\FieldMapper;
use NineteenNinetyFour\Ingest\Mapping\Mapper;
use Statamic\Fields\Field;

class MapPinMapper implements FieldMapper
{
    public function map(mixed $value, Field $field, array $mapping, Context $context, Mapper $mapper): mixed
    {
        [$lat, $lng] = explode(',', (string) $value);

        return ['lat' => (float) $lat, 'lng' => (float) $lng];
    }
}

Ingest::fieldMapper('map_pin', MapPinMapper::class);
```
