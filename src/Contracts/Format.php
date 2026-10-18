<?php

namespace NineteenNinetyFour\Ingest\Contracts;

use Generator;

/**
 * Reads a downloaded feed file into items.
 */
interface Format
{
    public static function handle(): string;

    public static function label(): string;

    /**
     * How likely a file is this format, 0–100, from its first bytes.
     */
    public static function sniff(string $head, ?string $contentType, ?string $extension): int;

    /**
     * Each item at the item path, one at a time, without loading the whole
     * file where the format allows it.
     *
     * @param  array<string, mixed>  $options  item_path plus format options
     * @return Generator<int, array<string, mixed>>
     */
    public function items(string $file, array $options): Generator;

    /**
     * The whole document as an array, for previews and pagination links.
     * Only called on small files.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function document(string $file, array $options): array;

    /**
     * The item path to use when a feed doesn't set one.
     */
    public function defaultItemPath(): ?string;
}
