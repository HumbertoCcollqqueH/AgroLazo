<?php

use App\Http\Controllers\Admin\ProducerController;
use App\Http\Middleware\EnsureActiveUser;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'auth.session', EnsureActiveUser::class, 'cache.headers:private;no_store;max_age=0'])
    ->prefix('admin/producers')->name('producers.')->group(function (): void {
        Route::get('/', [ProducerController::class, 'index'])->name('index');
        Route::get('/create', [ProducerController::class, 'create'])->name('create');
        Route::post('/', [ProducerController::class, 'store'])->name('store');
        Route::get('/{producer}', [ProducerController::class, 'show'])->whereNumber('producer')->name('show');
        Route::get('/{producer}/edit', [ProducerController::class, 'edit'])->whereNumber('producer')->name('edit');
        Route::patch('/{producer}', [ProducerController::class, 'update'])->whereNumber('producer')->name('update');
        Route::post('/{producer}/verify', [ProducerController::class, 'verify'])->whereNumber('producer')->name('verify');
        Route::patch('/{producer}/phone', [ProducerController::class, 'changePhone'])->whereNumber('producer')->name('change-phone');
        Route::post('/{producer}/consents', [ProducerController::class, 'consent'])->whereNumber('producer')->name('consent');
    });
