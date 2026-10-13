<?php

use App\Http\Controllers\CommerceController;
use App\Http\Middleware\EnsureActiveUser;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'auth.session', EnsureActiveUser::class, 'cache.headers:private;no_store;max_age=0'])->prefix('commerce')->name('commerce.')->group(function (): void {
    Route::get('/', [CommerceController::class, 'index'])->name('index');
    Route::get('/lots/{lot}/quote', [CommerceController::class, 'create'])->whereNumber('lot')->name('quotes.create');
    Route::post('/lots/{lot}/quotes', [CommerceController::class, 'quote'])->whereNumber('lot')->name('quotes.store');
    Route::get('/quotes/{quote}', [CommerceController::class, 'showQuote'])->whereNumber('quote')->name('quotes.show');
    Route::post('/quotes/{quote}/reserve', [CommerceController::class, 'reserve'])->whereNumber('quote')->name('reserve');
    Route::post('/quotes/{quote}/confirm', [CommerceController::class, 'confirm'])->whereNumber('quote')->name('confirm');
    Route::post('/reservations/{reservation}/cancel', [CommerceController::class, 'cancelReservation'])->whereNumber('reservation')->name('reservations.cancel');
    Route::get('/orders/{order}', [CommerceController::class, 'showOrder'])->whereNumber('order')->name('orders.show');
    Route::post('/orders/{order}/delivery-address', [CommerceController::class, 'deliveryAddress'])->whereNumber('order')->name('orders.delivery-address');
    Route::post('/orders/{order}/reductions', [CommerceController::class, 'reduction'])->whereNumber('order')->name('reductions.store');
    Route::post('/adjustments/{adjustment}/accept', [CommerceController::class, 'acceptReduction'])->whereNumber('adjustment')->name('adjustments.accept');
    Route::get('/pricing', [CommerceController::class, 'pricing'])->name('pricing');
    Route::post('/pricing/policies', [CommerceController::class, 'policy'])->name('pricing.policies');
    Route::post('/pricing/references', [CommerceController::class, 'reference'])->name('pricing.references');
});
