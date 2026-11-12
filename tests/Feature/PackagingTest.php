<?php

namespace NineteenNinetyFour\Ingest\Tests\Feature;

use Illuminate\Support\Facades\View;
use NineteenNinetyFour\Ingest\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class PackagingTest extends TestCase
{
    /**
     * The addon has no Blade views. Registering a view folder it doesn't
     * ship breaks `php artisan view:cache`, which compiles every one.
     */
    #[Test]
    public function the_addon_registers_no_view_folder(): void
    {
        $this->assertArrayNotHasKey('ingest', View::getFinder()->getHints());
    }
}
