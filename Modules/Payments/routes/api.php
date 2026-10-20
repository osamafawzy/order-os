<?php

use Illuminate\Support\Facades\Route;
use Modules\Payments\Http\Controllers\OrderPaymentController;
use Modules\Payments\Http\Controllers\PaymentController;
use Modules\Payments\Http\Controllers\PaymentGatewayController;

Route::middleware(['auth:api', 'throttle:api'])->group(function (): void {
    Route::get('payment-gateways', [PaymentGatewayController::class, 'index'])->name('payment-gateways.index');

    Route::get('orders/{order}/payments', [OrderPaymentController::class, 'index'])
        ->whereNumber('order')
        ->name('orders.payments.index');
    Route::post('orders/{order}/payments', [OrderPaymentController::class, 'store'])
        ->whereNumber('order')
        ->name('orders.payments.store');

    Route::apiResource('payments', PaymentController::class)
        ->only(['index', 'show'])
        ->whereNumber('payment');
});
