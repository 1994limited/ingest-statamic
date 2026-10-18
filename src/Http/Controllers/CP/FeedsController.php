<?php

namespace NineteenNinetyFour\Ingest\Http\Controllers\CP;

use Statamic\Http\Controllers\CP\CpController;

class FeedsController extends CpController
{
    public function index()
    {
        $this->authorize('view ingest');

        return 'Ingest';
    }
}
