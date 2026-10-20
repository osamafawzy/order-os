<?php

use Illuminate\Support\Facades\Route;
use Modules\Orders\Http\Controllers\OrderController;

Route::middleware(['auth:api', 'throttle:api'])->group(function (): void {
    // PATCH only. An update changes just the fields sent, which is PATCH's
    // meaning; PUT promises a full replacement this endpoint does not do.
    Route::apiResource('orders', OrderController::class)
        ->except('update')
        ->whereNumber('order');

    Route::patch('orders/{order}', [OrderController::class, 'update'])
        ->whereNumber('order')
        ->name('orders.update');
});
