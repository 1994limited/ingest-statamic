<?php

namespace NineteenNinetyFour\Ingest\Destinations;

use NineteenNinetyFour\Ingest\Contracts\Destination;
use NineteenNinetyFour\Ingest\Feeds\Feed;
use NineteenNinetyFour\Ingest\IngestException;
use NineteenNinetyFour\Ingest\Integrations\Cargo\Cargo;
use NineteenNinetyFour\Ingest\Integrations\Cargo\CargoProductsDestination;

/**
 * Where feeds can write. Add your own with Ingest::destination().
 */
class Destinations
{
    /** @var array<string, class-string<Destination>> */
    private array $destinations = [];

    public function __construct()
    {
        foreach ([EntriesDestination::class, TermsDestination::class, GlobalsDestination::class, UsersDestination::class, CargoProductsDestination::class] as $class) {
            $this->register($class);
        }
    }

    /** @param class-string<Destination> $class */
    public function register(string $class): void
    {
        $this->destinations[$class::handle()] = $class;
    }

    public function make(string $handle): Destination
    {
        if (! isset($this->destinations[$handle])) {
            throw new IngestException("Unknown destination [{$handle}].");
        }

        return app($this->destinations[$handle]);
    }

    public function for(Feed $feed): Destination
    {
        return $this->make((string) ($feed->destination()['type'] ?? 'entries'));
    }

    /** @return array<string, string> */
    public function options(): array
    {
        return collect($this->destinations)
            ->reject(fn ($class, $handle) => $handle === 'cargo_products' && ! Cargo::installed())
            ->map(fn ($class) => $class::label())
            ->all();
    }
}
