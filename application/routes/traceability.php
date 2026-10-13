<?php

use App\Http\Controllers\TraceabilityController;
use App\Http\Middleware\EnsureActiveUser;
use Illuminate\Support\Facades\Route;

Route::middleware(['cache.headers:no_store;max_age=0', 'throttle:60,1'])->group(function (): void {
    Route::get('/trace/{token}', [TraceabilityController::class, 'show'])->where('token', '[A-Za-z0-9]{32}')->name('trace.show');
    Route::get('/trace/{token}/qr.svg', [TraceabilityController::class, 'qr'])->where('token', '[A-Za-z0-9]{32}')->name('trace.qr');
    Route::get('/api/v1/traces/{token}', [TraceabilityController::class, 'show'])->where('token', '[A-Za-z0-9]{32}')->name('api.traces.show');
});
Route::middleware(['auth', 'auth.session', EnsureActiveUser::class, 'cache.headers:private;no_store;max_age=0'])->group(function (): void {
    Route::get('/commerce/lots/{lot}/trace', [TraceabilityController::class, 'manage'])->whereNumber('lot')->name('trace.manage');
    Route::post('/commerce/lots/{lot}/trace', [TraceabilityController::class, 'publish'])->whereNumber('lot')->name('trace.publish');
    Route::post('/commerce/traces/{publication}/revoke', [TraceabilityController::class, 'revoke'])->whereNumber('publication')->name('trace.revoke');
    Route::get('/commerce/orders/{order}/trace', [TraceabilityController::class, 'chain'])->whereNumber('order')->name('trace.chain');
    Route::get('/api/v1/orders/{order}/trace', [TraceabilityController::class, 'chain'])->whereNumber('order')->name('api.orders.trace');
});
