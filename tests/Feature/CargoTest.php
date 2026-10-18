<?php

namespace NineteenNinetyFour\Ingest\Tests\Feature;

use DuncanMcClean\Cargo\Facades\Product;
use NineteenNinetyFour\Ingest\Integrations\Cargo\Cargo;
use NineteenNinetyFour\Ingest\Runs\RunStatus;
use NineteenNinetyFour\Ingest\Tests\CargoTestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Entry;

/**
 * In their own processes: Cargo registers things statically (action
 * routes, search providers) that would leak into tests without Cargo.
 */
#[RunTestsInSeparateProcesses]
class CargoTest extends CargoTestCase
{
    private function editionsFeed(array $overrides = []): void
    {
        $this->saveFeed('catalogue', array_replace_recursive([
            'source' => ['type' => 'url', 'url' => 'https://feeds.test/states/books/v1.json'],
            'format' => ['item_path' => 'data'],
            'destination' => ['type' => 'cargo_products', 'collection' => 'products', 'blueprint' => 'book_editions', 'tax_class' => 'general'],
            'key' => ['isbn'],
            'mapping' => [
                'title' => ['path' => 'title'],
                'isbn' => ['path' => 'isbn'],
                'product_variants' => [
                    'path' => 'formats',
                    'dimensions' => [['name' => 'Format', 'path' => 'binding']],
                    'sku' => 'sku',
                    'price' => ['path' => 'price'],
                    'stock' => ['path' => 'stock'],
                ],
            ],
        ], $overrides));
    }

    #[Test]
    public function the_integration_checks_the_cargo_version(): void
    {
        $this->assertTrue(Cargo::installed());
        $this->assertTrue(Cargo::supported('1.15.0'));
        $this->assertTrue(Cargo::supported('1.99.3'));
        $this->assertFalse(Cargo::supported('1.14.9'));
        $this->assertFalse(Cargo::supported('2.0.0'));
    }

    #[Test]
    public function products_are_created_with_variants_prices_and_stock(): void
    {
        $this->editionsFeed();

        $run = $this->ingest('catalogue');

        $this->assertSame(RunStatus::Completed, $run->status, (string) $run->message);
        $this->assertSame(20, $run->counts['created']);

        $source = $this->fixture('states/books/v1.json')['data'][0];
        $product = Product::find(Entry::query()->where('collection', 'products')->where('isbn', $source['isbn'])->first()->id());

        $this->assertTrue($product->isVariantProduct());
        $this->assertSame('general', $product->get('tax_class'));

        $paperback = $product->variant('Paperback');
        $this->assertNotNull($paperback);
        $this->assertSame((int) round($source['formats'][1]['price'] * 100), $paperback->price());
        $this->assertSame($source['formats'][1]['stock'], $paperback->stock());
        $this->assertSame($source['formats'][1]['sku'], $product->get('product_variants')['options'][1]['sku']);
        $this->assertSame(['Hardback', 'Paperback'], array_slice($product->get('product_variants')['variants'][0]['values'], 0, 2));
    }

    #[Test]
    public function a_price_change_updates_one_variant_and_keeps_option_fields(): void
    {
        $this->editionsFeed();
        $this->ingest('catalogue');

        $entry = Entry::query()->where('collection', 'products')->first();
        $variants = $entry->get('product_variants');
        $variants['options'][0]['note'] = 'Signed';
        $entry->set('product_variants', $variants)->save();

        $this->point('catalogue', 'states/books/v2.json');
        $run = $this->ingest('catalogue');

        $this->assertSame(6, $run->counts['updated']);
        $this->assertSame('Signed', Entry::find($entry->id())->get('product_variants')['options'][0]['note']);
    }

    #[Test]
    public function a_stock_feed_matched_by_sku_updates_only_stock(): void
    {
        // Simple products keyed on the paperback SKU, then a stock-and-price CSV.
        $this->saveFeed('stock', [
            'source' => ['type' => 'url', 'url' => 'https://feeds.test/books/stock.csv'],
            'destination' => ['type' => 'cargo_products', 'collection' => 'products', 'blueprint' => 'product', 'tax_class' => 'general'],
            'key' => ['sku'],
            'filters' => [['path' => 'sku', 'op' => 'contains', 'value' => '-PB']],
            'mapping' => ['title' => ['path' => 'sku'], 'price' => ['path' => 'price'], 'stock' => ['path' => 'stock']],
        ]);

        $run = $this->ingest('stock');

        $this->assertSame(60, $run->counts['created'], (string) $run->message);
        $product = Product::find(Entry::query()->where('collection', 'products')->first()->id());
        $this->assertIsInt($product->price());
        $this->assertIsInt($product->stock());
    }

    #[Test]
    public function stock_can_only_go_down(): void
    {
        $this->editionsFeed(['mapping' => ['product_variants' => ['stock' => ['path' => 'stock', 'mode' => 'lower_only']]]]);
        $this->ingest('catalogue');

        $entry = Entry::query()->where('collection', 'products')->first();
        $variants = $entry->get('product_variants');
        $variants['options'][1]['stock'] = 0;
        $entry->set('product_variants', $variants)->save();

        $this->ingest('catalogue', options: ['force' => true]);

        $this->assertSame(0, Entry::find($entry->id())->get('product_variants')['options'][1]['stock']);
    }

    #[Test]
    public function the_destination_must_be_a_cargo_product_collection(): void
    {
        $this->editionsFeed(['destination' => ['collection' => 'books', 'blueprint' => 'book']]);

        $run = $this->ingest('catalogue');

        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertStringContainsString('product collections', $run->message);
    }

    #[Test]
    public function a_stock_feed_updates_variant_options_and_simple_products_by_sku(): void
    {
        $this->editionsFeed();
        $this->ingest('catalogue');
        Entry::make()->collection('products')->blueprint('product')->slug('tote')->data(['title' => 'Tote', 'sku' => 'TOTE-1', 'price' => 1000, 'stock' => 5, 'tax_class' => 'general'])->save();

        $books = $this->fixture('states/books/v1.json')['data'];
        $rows = "sku,price,stock\n{$books[0]['formats'][1]['sku']},99.50,3\nTOTE-1,12.00,40\nNOPE-1,1.00,1\n";
        file_put_contents(self::TMP.'/stock.csv', $rows);
        $this->serve('stock.csv', self::TMP.'/stock.csv');

        $this->saveFeed('stock', [
            'source' => ['type' => 'url', 'url' => 'https://feeds.test/stock.csv'],
            'destination' => ['type' => 'cargo_stock', 'collection' => 'products'],
            'key' => ['sku'],
            'mapping' => ['price' => ['path' => 'price'], 'stock' => ['path' => 'stock']],
        ]);

        $run = $this->ingest('stock');

        $this->assertSame(2, $run->counts['updated'], (string) $run->message);
        $this->assertSame(1, $run->counts['skipped']);

        $book = Entry::query()->where('collection', 'products')->where('isbn', $books[0]['isbn'])->first();
        $paperback = collect($book->get('product_variants')['options'])->firstWhere('key', 'Paperback');
        $this->assertSame(9950, $paperback['price']);
        $this->assertSame(3, $paperback['stock']);
        $this->assertSame($books[0]['formats'][0]['stock'], collect($book->get('product_variants')['options'])->firstWhere('key', 'Hardback')['stock']);

        $tote = Entry::query()->where('slug', 'tote')->first();
        $this->assertSame(1200, $tote->get('price'));
        $this->assertSame(40, $tote->get('stock'));

        // Nothing changed: nothing written.
        $this->assertSame(2, $this->ingest('stock', options: ['force' => true])->counts['unchanged']);
    }

    #[Test]
    public function price_and_stock_can_be_set_only_for_new_options(): void
    {
        $this->editionsFeed(['mapping' => ['product_variants' => ['price' => ['path' => 'price', 'create_only' => true], 'stock' => ['path' => 'stock', 'create_only' => true]]]]);
        $this->ingest('catalogue');

        $entry = Entry::query()->where('collection', 'products')->first();
        $variants = $entry->get('product_variants');
        $variants['options'][1]['stock'] = 999;
        $variants['options'][1]['price'] = 1;
        $entry->set('product_variants', $variants)->save();

        $this->ingest('catalogue', options: ['force' => true]);

        $after = Entry::find($entry->id())->get('product_variants')['options'][1];
        $this->assertSame(999, $after['stock']);
        $this->assertSame(1, $after['price']);
    }

    #[Test]
    public function fields_can_be_set_only_when_created(): void
    {
        $this->editionsFeed(['mapping' => ['title' => ['path' => 'title', 'create_only' => true]]]);
        $this->ingest('catalogue');
        $entry = Entry::query()->where('collection', 'products')->first();
        $entry->set('title', 'Renamed by the shop')->save();

        $this->point('catalogue', 'states/books/v2.json');
        $this->ingest('catalogue', options: ['force' => true]);

        $this->assertSame('Renamed by the shop', Entry::find($entry->id())->get('title'));
    }
}
