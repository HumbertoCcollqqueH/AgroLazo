<?php

use App\Http\Controllers\FulfillmentController;
use App\Http\Middleware\EnsureActiveUser;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'auth.session', EnsureActiveUser::class, 'cache.headers:private;no_store;max_age=0'])->prefix('fulfillment')->name('fulfillment.')->group(function (): void {
    Route::get('/points', [FulfillmentController::class, 'points'])->name('points');
    Route::post('/points', [FulfillmentController::class, 'point'])->name('points.store');
    Route::post('/orders/{order}/schedule', [FulfillmentController::class, 'schedule'])->whereNumber('order')->name('schedule');
    Route::post('/orders/{order}/notices', [FulfillmentController::class, 'recoverNotices'])->whereNumber('order')->name('notices.recover');
    Route::post('/events/{event}/receipts', [FulfillmentController::class, 'receipt'])->whereNumber('event')->name('receipts');
    Route::post('/receipts/{receipt}/photos', [FulfillmentController::class, 'photo'])->whereNumber('receipt')->name('photos');
    Route::get('/photos/{photo}', [FulfillmentController::class, 'download'])->whereNumber('photo')->name('photo');
    Route::post('/orders/{order}/physical-lots', [FulfillmentController::class, 'consolidate'])->whereNumber('order')->name('consolidate');
    Route::post('/orders/{order}/shipments', [FulfillmentController::class, 'shipment'])->whereNumber('order')->name('shipment');
    Route::post('/shipments/{shipment}/dispatch', [FulfillmentController::class, 'dispatch'])->whereNumber('shipment')->name('dispatch');
    Route::post('/shipments/{shipment}/outcome', [FulfillmentController::class, 'outcome'])->whereNumber('shipment')->name('outcome');
    Route::post('/incidents/{incident}/resolve', [FulfillmentController::class, 'resolve'])->whereNumber('incident')->name('resolve');
    Route::post('/notices/{notice}/retry', [FulfillmentController::class, 'retryNotice'])->whereNumber('notice')->name('notices.retry');
});
