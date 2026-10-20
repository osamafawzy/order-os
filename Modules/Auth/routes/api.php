<?php

use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Controllers\AuthController;

Route::prefix('auth')->name('auth.')->controller(AuthController::class)->group(function (): void {
    Route::middleware('throttle:auth')->group(function (): void {
        Route::post('register', 'register')->name('register');
        Route::post('login', 'login')->name('login');
    });

    Route::middleware('auth:api')->group(function (): void {
        Route::get('me', 'me')->name('me');
        Route::post('refresh', 'refresh')->name('refresh');
        Route::post('logout', 'logout')->name('logout');
    });
});
