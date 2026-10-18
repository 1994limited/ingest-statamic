<?php

namespace NineteenNinetyFour\Ingest\Mapping\Fields;

use NineteenNinetyFour\Ingest\Assets\AssetDownloader;
use NineteenNinetyFour\Ingest\Mapping\Context;
use NineteenNinetyFour\Ingest\Mapping\FieldError;
use NineteenNinetyFour\Ingest\Mapping\FieldMapper;
use NineteenNinetyFour\Ingest\Mapping\Mapper;
use NineteenNinetyFour\Ingest\Support\Path;
use NineteenNinetyFour\Ingest\Support\Template;
use NineteenNinetyFour\Ingest\Support\Value;
use Statamic\Facades\AssetContainer;
use Statamic\Fields\Field;
use Throwable;

/**
 * Assets fields, from URLs (downloaded, see AssetDownloader) or from paths
 * of files already in the container ("source: existing").
 *
 *   folder   where downloads go, with placeholders: books/{isbn}
 *   alt      a path for the alt text
 *   refresh  check already-downloaded URLs for a changed file
 */
class AssetsMapper implements FieldMapper
{
    public function __construct(private AssetDownloader $downloader) {}

    public function map(mixed $value, Field $field, array $mapping, Context $context, Mapper $mapper): mixed
    {
        $handle = $mapping['container'] ?? $field->get('container') ?? AssetContainer::all()->first()?->handle();
        $container = $handle ? AssetContainer::find($handle) : null;

        if (! $container) {
            throw new FieldError('There\'s no asset container to put files in.');
        }

        $values = array_values(array_filter(array_map(
            fn ($v) => is_array($v) ? Value::text($v['url'] ?? $v['@url'] ?? $v['@href'] ?? $v['src'] ?? null) : Value::text($v),
            Path::isList($value) ? $value : [$value]
        ), fn ($v) => $v !== ''));

        $max = (int) $field->get('max_files', 0);
        if ($max > 0) {
            $values = array_slice($values, 0, $max);
        }

        $folder = Template::render((string) ($mapping['folder'] ?? $field->get('folder') ?? ''), $context->item);
        $alt = isset($mapping['alt']) ? Value::text($mapper->read((string) $mapping['alt'], $context->item, $context)) : null;

        $paths = [];
        foreach ($values as $one) {
            if (($mapping['source'] ?? 'url') === 'existing') {
                $asset = $container->asset(ltrim($one, '/')) ?? $container->queryAssets()->where('basename', basename($one))->first();
                if (! $asset) {
                    $context->warn("{$field->display()}: there's no asset at {$one}.");

                    continue;
                }
                $paths[] = $asset->path();

                continue;
            }

            try {
                $asset = $this->downloader->download($one, $container, $folder, $context, ['alt' => $alt, 'refresh' => ! empty($mapping['refresh'])]);
            } catch (FieldError $e) {
                // One bad image shouldn't lose the others.
                $context->warn("{$field->display()}: {$e->getMessage()}");

                continue;
            } catch (Throwable $e) {
                report($e);
                $context->warn("{$field->display()}: {$e->getMessage()}");

                continue;
            }

            if ($asset) {
                $paths[] = $asset->path();
            }
        }

        if ($context->dryRun && $paths === [] && $values !== []) {
            // Not downloaded in a dry run: show the URLs as the new value.
            return $max === 1 ? $values[0] : $values;
        }

        return $max === 1 ? ($paths[0] ?? null) : $paths;
    }
}
