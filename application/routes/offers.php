<?php

use App\Http\Controllers\Admin\OfferController;
use App\Http\Middleware\EnsureActiveUser;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'auth.session', EnsureActiveUser::class, 'cache.headers:private;no_store;max_age=0'])->prefix('admin/offers')->name('offers.')->group(function (): void {
    Route::get('/', [OfferController::class, 'index'])->name('index');
    Route::get('/create', [OfferController::class, 'create'])->name('create');
    Route::post('/', [OfferController::class, 'store'])->name('store');
    Route::get('/{offer}', [OfferController::class, 'show'])->whereNumber('offer')->name('show');
    Route::get('/{offer}/edit', [OfferController::class, 'edit'])->whereNumber('offer')->name('edit');
    Route::patch('/{offer}', [OfferController::class, 'update'])->whereNumber('offer')->name('update');
    Route::post('/{offer}/transition', [OfferController::class, 'transition'])->name('transition');
    Route::post('/{offer}/publish', [OfferController::class, 'publish'])->whereNumber('offer')->name('publish');
    Route::post('/{offer}/agreements', [OfferController::class, 'propose'])->name('propose');
    Route::post('/{offer}/agreements/{agreement}', [OfferController::class, 'decide'])->name('decide');
    Route::post('/{offer}/photos', [OfferController::class, 'upload'])->name('upload');
    Route::get('/{offer}/photos/{photo}', [OfferController::class, 'photo'])->name('photo');
});
