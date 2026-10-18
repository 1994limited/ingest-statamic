<?php

namespace NineteenNinetyFour\Ingest\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \NineteenNinetyFour\Ingest\Contracts\FeedRepository feeds()
 * @method static \NineteenNinetyFour\Ingest\Feeds\Feed|null feed(string $handle)
 * @method static \NineteenNinetyFour\Ingest\Runs\Run run(string|\NineteenNinetyFour\Ingest\Feeds\Feed $feed, bool $dryRun = false, string $trigger = 'manual', array $options = [])
 * @method static void transform(string $name, callable $transform)
 * @method static void hook(string $name, string $class)
 * @method static void source(string $class)
 * @method static void format(string $class)
 * @method static void destination(string $class)
 * @method static void fieldMapper(string $fieldtype, string $class)
 *
 * @see \NineteenNinetyFour\Ingest\Ingest
 */
class Ingest extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \NineteenNinetyFour\Ingest\Ingest::class;
    }
}
