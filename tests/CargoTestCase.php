<?php

namespace NineteenNinetyFour\Ingest\Tests;

use DuncanMcClean\Cargo\Discounts\DiscountServiceProvider;
use DuncanMcClean\Cargo\Orders\OrderServiceProvider;
use DuncanMcClean\Cargo\Payments\PaymentServiceProvider;
use DuncanMcClean\Cargo\ServiceProvider as CargoServiceProvider;
use DuncanMcClean\Cargo\Shipping\ShippingServiceProvider;
use NineteenNinetyFour\Ingest\Integrations\Cargo\Cargo;
use Statamic\Addons\Manifest;
use Statamic\Facades\Collection;
use Statamic\Facades\Site;
use Stillat\Proteus\WriterServiceProvider;

/**
 * Tests with Cargo installed: a products collection with simple books
 * (price, stock) and books with editions (variants).
 */
abstract class CargoTestCase extends TestCase
{
    protected function getPackageProviders($app)
    {
        return array_merge([
            CargoServiceProvider::class,
            DiscountServiceProvider::class,
            OrderServiceProvider::class,
            PaymentServiceProvider::class,
            ShippingServiceProvider::class,
            WriterServiceProvider::class,
        ], parent::getPackageProviders($app));
    }

    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        $app['config']->set('statamic.cargo', require __DIR__.'/../vendor/duncanmcclean/statamic-cargo/config/cargo.php');
    }

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app->make(Manifest::class)->manifest['duncanmcclean/statamic-cargo'] = [
            'id' => 'duncanmcclean/statamic-cargo',
            'slug' => 'cargo',
            'version' => '1.15.0',
            'namespace' => 'DuncanMcClean\\Cargo',
            'autoload' => 'src',
            'provider' => CargoServiceProvider::class,
        ];

        foreach (['carts', 'orders', 'discounts'] as $dir) {
            $app['config']->set("statamic.cargo.{$dir}.directory", self::TMP.'/cargo/'.$dir);
        }
        $app['config']->set('statamic.cargo.taxes.tax_classes.directory', self::TMP.'/cargo/tax-classes');
        $app['config']->set('statamic.cargo.taxes.tax_zones.directory', self::TMP.'/cargo/tax-zones');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Cargo::flush();

        Site::setSites(['default' => ['name' => 'Dog-ear Books', 'url' => 'http://localhost/', 'locale' => 'en_GB', 'attributes' => ['currency' => 'GBP']]])->save();
        Collection::make('products')->routes('/books/{slug}')->save();

        $this->blueprint('collections.products', 'product', [
            'title' => ['type' => 'text'],
            'isbn' => ['type' => 'text'],
            'price' => ['type' => 'money'],
            'stock' => ['type' => 'integer'],
            'tax_class' => ['type' => 'tax_classes', 'max_items' => 1],
        ]);

        $this->blueprint('collections.products', 'book_editions', [
            'title' => ['type' => 'text'],
            'isbn' => ['type' => 'text'],
            'product_variants' => ['type' => 'product_variants', 'option_fields' => [
                ['handle' => 'sku', 'field' => ['type' => 'text']],
                ['handle' => 'stock', 'field' => ['type' => 'integer']],
            ]],
            'tax_class' => ['type' => 'tax_classes', 'max_items' => 1],
        ]);
    }
}
