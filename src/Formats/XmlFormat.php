<?php

namespace NineteenNinetyFour\Ingest\Formats;

use DOMDocument;
use DOMElement;
use DOMNode;
use Generator;
use NineteenNinetyFour\Ingest\Contracts\Format;
use NineteenNinetyFour\Ingest\IngestException;
use NineteenNinetyFour\Ingest\Support\Path;
use XMLReader;

/**
 * XML, streamed with XMLReader: only one item is in memory at a time.
 *
 * An element becomes an array: attributes as "@name", children by name
 * (several with the same name become a list), and text on its own as a
 * plain string, or as "#text" next to attributes.
 *
 * Hardened: no network access, no DTD loading, no entity expansion.
 */
class XmlFormat implements Format
{
    public static function handle(): string
    {
        return 'xml';
    }

    public static function label(): string
    {
        return 'XML';
    }

    public static function sniff(string $head, ?string $contentType, ?string $extension): int
    {
        $head = ltrim($head);

        if (! str_starts_with($head, '<') || preg_match('/^<(!doctype\s+html|html)/i', $head)) {
            return 0;
        }

        return 70;
    }

    public function defaultItemPath(): ?string
    {
        return null;
    }

    public function items(string $file, array $options): Generator
    {
        $target = array_values(array_filter(Path::segments((string) ($options['item_path'] ?? $this->defaultItemPath() ?? '')), fn ($s) => ! in_array($s, ['*', '~'], true)));

        if ($target === []) {
            // No item path: the root element is the one item.
            yield $this->finish(array_values($this->document($file, $options))[0] ?? []);

            return;
        }

        [$previous, $loader] = $this->harden();

        $reader = new XMLReader;
        if (! $reader->open($file, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_BIGLINES)) {
            $this->restore($previous, $loader);

            throw new IngestException("The XML file couldn't be opened.");
        }

        $stack = [];

        try {
            // libxml reports problems through throwErrors(); PHP warnings are muted.
            $ok = @$reader->read();

            while ($ok) {
                if ($reader->nodeType === XMLReader::ELEMENT) {
                    $stack[] = [$reader->name, $reader->localName];

                    if ($this->matches($stack, $target)) {
                        $node = @$reader->expand();
                        if ($node === false) {
                            $this->throwErrors();

                            throw new IngestException("The XML isn't well formed.");
                        }
                        if ($node instanceof DOMElement) {
                            $value = self::toArray($node);
                            yield $this->finish(is_array($value) ? $value : ['value' => $value]);
                        }
                        array_pop($stack);
                        $ok = @$reader->next();

                        continue;
                    }

                    if ($reader->isEmptyElement) {
                        array_pop($stack);
                    }
                } elseif ($reader->nodeType === XMLReader::END_ELEMENT) {
                    array_pop($stack);
                }

                $ok = @$reader->read();
            }

            $this->throwErrors();
        } finally {
            $reader->close();
            $this->restore($previous, $loader);
        }
    }

    public function document(string $file, array $options): array
    {
        [$previous, $loader] = $this->harden();

        try {
            $doc = new DOMDocument;
            $loaded = @$doc->load($file, LIBXML_NONET | LIBXML_COMPACT | LIBXML_BIGLINES);
            $this->throwErrors();

            if (! $loaded || ! $doc->documentElement) {
                throw new IngestException("The XML isn't well formed.");
            }

            return [$doc->documentElement->nodeName => self::toArray($doc->documentElement)];
        } finally {
            $this->restore($previous, $loader);
        }
    }

    /**
     * Formats built on XML (RSS, Atom) add normalised fields here.
     */
    protected function finish(array $item): array
    {
        return $item;
    }

    public static function toArray(DOMElement $element): array|string
    {
        $out = [];
        $lists = [];
        $text = '';
        $hasChildren = false;

        foreach ($element->attributes ?? [] as $attribute) {
            $out['@'.$attribute->nodeName] = $attribute->value;
        }

        /** @var DOMNode $child */
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $hasChildren = true;
                $name = $child->nodeName;
                $value = self::toArray($child);

                if (array_key_exists($name, $out)) {
                    if (! isset($lists[$name])) {
                        $out[$name] = [$out[$name]];
                        $lists[$name] = true;
                    }
                    $out[$name][] = $value;
                } else {
                    $out[$name] = $value;
                }
            } elseif (in_array($child->nodeType, [XML_TEXT_NODE, XML_CDATA_SECTION_NODE], true)) {
                $text .= $child->nodeValue;
            }
        }

        if (! $hasChildren && $out === []) {
            return trim($text);
        }

        if (trim($text) !== '') {
            $out['#text'] = trim($text);
        }

        return $out;
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $stack
     * @param  string[]  $target
     */
    private function matches(array $stack, array $target): bool
    {
        if (count($stack) !== count($target)) {
            return false;
        }

        foreach ($target as $i => $name) {
            if ($stack[$i][0] !== $name && $stack[$i][1] !== $name) {
                return false;
            }
        }

        return true;
    }

    private function harden(): array
    {
        libxml_clear_errors();
        $previous = libxml_use_internal_errors(true);
        // Never load external entities or DTDs, whatever the document asks for.
        $loader = libxml_get_external_entity_loader();
        libxml_set_external_entity_loader(fn () => null);

        return [$previous, $loader];
    }

    private function restore(bool $previous, ?callable $loader): void
    {
        libxml_set_external_entity_loader($loader);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }

    private function throwErrors(): void
    {
        foreach (libxml_get_errors() as $error) {
            if ($error->level >= LIBXML_ERR_ERROR) {
                libxml_clear_errors();

                throw new IngestException(sprintf("The XML isn't well formed: %s (line %d).", trim($error->message), $error->line));
            }
        }
    }
}
