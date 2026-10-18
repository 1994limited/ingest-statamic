<?php

use Illuminate\Support\Facades\Route;
use NineteenNinetyFour\Ingest\Http\Controllers\CP\FeedsController;
use NineteenNinetyFour\Ingest\Http\Controllers\CP\MetaController;
use NineteenNinetyFour\Ingest\Http\Controllers\CP\PreviewController;
use NineteenNinetyFour\Ingest\Http\Controllers\CP\RunsController;

Route::prefix('ingest')->name('ingest.')->group(function () {
    Route::get('/', [FeedsController::class, 'index'])->name('index');

    Route::get('feeds/create', [FeedsController::class, 'create'])->name('feeds.create');
    Route::post('feeds', [FeedsController::class, 'store'])->name('feeds.store');
    Route::post('feeds/upload', [FeedsController::class, 'upload'])->name('feeds.upload');
    Route::get('feeds/{feed}', [FeedsController::class, 'show'])->name('feeds.show');
    Route::get('feeds/{feed}/edit', [FeedsController::class, 'edit'])->name('feeds.edit');
    Route::put('feeds/{feed}', [FeedsController::class, 'update'])->name('feeds.update');
    Route::delete('feeds/{feed}', [FeedsController::class, 'destroy'])->name('feeds.destroy');
    Route::post('feeds/{feed}/duplicate', [FeedsController::class, 'duplicate'])->name('feeds.duplicate');
    Route::post('feeds/{feed}/toggle', [FeedsController::class, 'toggle'])->name('feeds.toggle');
    Route::match(['post', 'delete'], 'feeds/{feed}/webhook', [FeedsController::class, 'webhook'])->name('feeds.webhook');
    Route::get('feeds/{feed}/download', [FeedsController::class, 'download'])->name('feeds.download');
    Route::post('feeds/{feed}/run', [RunsController::class, 'start'])->name('feeds.run');

    Route::get('feeds/{feed}/runs/{run}', [RunsController::class, 'show'])->name('runs.show');
    Route::get('runs/{run}/status', [RunsController::class, 'status'])->name('runs.status');
    Route::get('runs/{run}/items', [RunsController::class, 'items'])->name('runs.items');
    Route::post('runs/{run}/cancel', [RunsController::class, 'cancel'])->name('runs.cancel');
    Route::post('runs/{run}/undo', [RunsController::class, 'undo'])->name('runs.undo');

    Route::post('preview', PreviewController::class)->name('preview');
    Route::get('meta/options', [MetaController::class, 'options'])->name('meta.options');
    Route::post('meta/fields', [MetaController::class, 'fields'])->name('meta.fields');
});
