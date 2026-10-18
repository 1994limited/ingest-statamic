<?php

namespace NineteenNinetyFour\Ingest\Tests\Unit;

use NineteenNinetyFour\Ingest\Http\UrlGuard;
use NineteenNinetyFour\Ingest\Mapping\FieldError;
use NineteenNinetyFour\Ingest\Mapping\Transforms;
use NineteenNinetyFour\Ingest\Sources\Pagination;
use NineteenNinetyFour\Ingest\Support\Filters;
use NineteenNinetyFour\Ingest\Support\Path;
use NineteenNinetyFour\Ingest\Support\Url;
use NineteenNinetyFour\Ingest\Support\Value;
use NineteenNinetyFour\Ingest\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class SupportTest extends TestCase
{
    private array $book = [
        'title' => 'Atlas',
        'formats' => [['sku' => 'A-HB', 'price' => '20.00'], ['sku' => 'A-PB', 'price' => '9.99']],
        'contributors' => ['contributor' => ['@role' => 'author', '#text' => 'Ada']],
        'prices' => ['gbp' => 10, 'eur' => 12],
        'subjects' => ['Crime', 'History'],
    ];

    #[Test]
    public function paths(): void
    {
        $this->assertSame('Atlas', Path::get($this->book, 'title'));
        $this->assertSame('A-PB', Path::get($this->book, 'formats.1.sku'));
        $this->assertSame(['A-HB', 'A-PB'], Path::get($this->book, 'formats.*.sku'));
        $this->assertSame(['A-HB', 'A-PB'], Path::get($this->book, 'formats/*/sku'));
        // One XML child read as a list of one.
        $this->assertSame(['author'], Path::get($this->book, 'contributors.contributor.*.@role'));
        $this->assertSame([10, 12], Path::get($this->book, 'prices.~'));
        $this->assertNull(Path::get($this->book, 'nope.deeper'));
        $this->assertSame(['Crime', 'History'], Path::many($this->book, 'subjects'));
        $this->assertSame(['Atlas'], Path::many($this->book, 'title'));
        $this->assertSame([], Path::many($this->book, 'nope'));
        $this->assertArrayHasKey('formats.*.sku', Path::flatten($this->book));
    }

    #[Test]
    public function values(): void
    {
        $this->assertSame('Ada', Value::unwrap(['@role' => 'author', '#text' => 'Ada']));
        $this->assertSame('a, b', Value::text(['a', 'b']));
        $this->assertTrue(Value::same(12, '12'));
        $this->assertTrue(Value::same(null, ''));
        $this->assertTrue(Value::same(['b' => 1, 'a' => 2], ['a' => 2, 'b' => 1]));
        $this->assertFalse(Value::same(['a', 'b'], ['b', 'a']));
    }

    public static function numbers(): array
    {
        return [
            ['12', 12], ['12.99', 12.99], ['12,99', 12.99], ['1,234', 1234], ['1,234.50', 1234.5],
            ['1.234,50', 1234.5], ['£1,234.50', 1234.5], ['1 234', 1234], ['-5', -5], ['1.234.567', 1234567],
        ];
    }

    #[Test]
    #[DataProvider('numbers')]
    public function numbers_in_any_common_format(string $text, int|float $expected): void
    {
        $this->assertEquals($expected, Transforms::number($text));
    }

    #[Test]
    public function not_a_number(): void
    {
        $this->expectException(FieldError::class);
        Transforms::number('lots');
    }

    #[Test]
    public function dates(): void
    {
        config(['app.timezone' => 'UTC']);

        $this->assertSame('2026-04-03', Transforms::parseDate('03/04/2026')->toDateString());
        $this->assertSame('2026-04-03', Transforms::parseDate('2026-04-03')->toDateString());
        $this->assertSame('2013-07-16 17:14:36', Transforms::parseDate('Tue, 16 Jul 2013 17:14:36 +0000')->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2008-05-07 20:43:46', Transforms::parseDate('1210193026')->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2017-12-01 01:00:30', Transforms::parseDate('1512090030615')->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-12-25', Transforms::parseDate('12/25/2026', 'm/d/Y')->toDateString());
        $this->assertSame('2026-06-01 08:00', Transforms::parseDate('2026-06-01 09:00', null, 'Europe/London')->utc()->format('Y-m-d H:i'));
    }

    #[Test]
    public function booleans(): void
    {
        foreach (['yes', 'Y', 'true', '1', 'on', 'live'] as $true) {
            $this->assertTrue(Transforms::boolean($true), $true);
        }
        foreach (['no', 'N', 'false', '0', 'off', 'draft', ''] as $false) {
            $this->assertFalse(Transforms::boolean($false), $false);
        }
        $this->assertTrue(Transforms::boolean('in stock', ['in stock']));

        $this->expectException(FieldError::class);
        Transforms::boolean('maybe');
    }

    #[Test]
    public function filters(): void
    {
        $this->assertTrue(Filters::passes($this->book, [['path' => 'title', 'op' => 'equals', 'value' => 'atlas']]));
        $this->assertTrue(Filters::passes($this->book, [['path' => 'subjects', 'op' => 'in', 'value' => 'crime, poetry']]));
        $this->assertFalse(Filters::passes($this->book, [['path' => 'subjects', 'op' => 'not_in', 'value' => ['Crime']]]));
        $this->assertTrue(Filters::passes($this->book, [['path' => 'formats.*.price', 'op' => 'lt', 'value' => 10]]));
        $this->assertTrue(Filters::passes($this->book, [['path' => 'nope', 'op' => 'empty']]));
        $this->assertTrue(Filters::passes($this->book, [['path' => 'title', 'op' => 'matches', 'value' => '^At']]));
        $this->assertFalse(Filters::passes($this->book, [['path' => 'title', 'op' => 'contains', 'value' => 'x'], ['path' => 'title', 'op' => 'not_empty']]));
    }

    #[Test]
    public function public_and_private_addresses(): void
    {
        foreach (['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'] as $public) {
            $this->assertTrue(UrlGuard::isPublic($public), $public);
        }
        foreach (['127.0.0.1', '10.1.2.3', '172.16.0.1', '192.168.1.1', '169.254.169.254', '100.64.0.1', '0.0.0.0', '::1', 'fd00::1', 'fe80::1', '::ffff:127.0.0.1', 'nonsense'] as $private) {
            $this->assertFalse(UrlGuard::isPublic($private), $private);
        }
    }

    #[Test]
    public function urls_resolve_against_the_page_they_came_from(): void
    {
        $base = 'https://feeds.test/books/paged/page-1.json?x=1';

        $this->assertSame('https://feeds.test/books/paged/page-2.json', Url::resolve($base, 'page-2.json'));
        $this->assertSame('https://feeds.test/other.json', Url::resolve($base, '/other.json'));
        $this->assertSame('https://feeds.test/books/x.json', Url::resolve($base, '../x.json'));
        $this->assertSame('https://feeds.test/books/paged/page-1.json?page=2', Url::resolve($base, '?page=2'));
        $this->assertSame('https://cdn.test/a.png', Url::resolve($base, '//cdn.test/a.png'));
        $this->assertSame('https://elsewhere.test/', Url::resolve($base, 'https://elsewhere.test/'));
    }

    #[Test]
    public function link_headers(): void
    {
        $this->assertSame('https://api.test/x?page=3', Pagination::linkHeaderNext('<https://api.test/x?page=1>; rel="first", <https://api.test/x?page=3>; rel="next"'));
        $this->assertSame('/x?cursor=abc', Pagination::linkHeaderNext('</x?cursor=abc>; rel=next'));
        $this->assertNull(Pagination::linkHeaderNext('<https://api.test/x?page=1>; rel="prev"'));
    }
}
