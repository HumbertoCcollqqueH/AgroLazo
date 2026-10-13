<?php

use App\Http\Controllers\Admin\LotController;
use App\Http\Controllers\MarketplaceController;
use App\Http\Middleware\EnsureActiveUser;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'auth.session', EnsureActiveUser::class, 'cache.headers:private;no_store;max_age=0'])->prefix('admin/lots')->name('lots.')->group(function (): void {
    Route::get('/', [LotController::class, 'index'])->name('index');
    Route::get('/create', [LotController::class, 'create'])->name('create');
    Route::post('/', [LotController::class, 'store'])->name('store');
    Route::get('/{lot}', [LotController::class, 'show'])->whereNumber('lot')->name('show');
    Route::post('/{lot}/transition', [LotController::class, 'transition'])->whereNumber('lot')->name('transition');
});

Route::middleware(['cache.headers:private;no_store;max_age=0'])->prefix('marketplace')->name('marketplace.')->group(function (): void {
    Route::get('/', [MarketplaceController::class, 'index'])->name('index');
    Route::get('/{code}', [MarketplaceController::class, 'show'])->where('code', 'L-[A-Z0-9]{8}')->name('show');
});
