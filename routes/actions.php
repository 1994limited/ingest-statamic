<?php

use Illuminate\Support\Facades\Route;
use NineteenNinetyFour\Ingest\Http\Controllers\WebhookController;

Route::post('feeds/{feed}/run', WebhookController::class)
    ->middleware('throttle:30,1')
    ->name('webhook');
