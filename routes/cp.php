<?php

use Illuminate\Support\Facades\Route;
use NineteenNinetyFour\Ingest\Http\Controllers\CP\FeedsController;

Route::prefix('ingest')->name('ingest.')->group(function () {
    Route::get('/', [FeedsController::class, 'index'])->name('index');
});
