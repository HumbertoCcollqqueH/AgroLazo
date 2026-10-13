<?php

use App\Http\Controllers\Admin\MessagingController;
use App\Http\Controllers\Admin\WhatsAppConnectionController;
use App\Http\Middleware\EnsureActiveUser;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'auth.session', EnsureActiveUser::class, 'cache.headers:private;no_store;max_age=0'])->prefix('admin/settings/whatsapp')->name('whatsapp.')->group(function (): void {
    Route::get('/', [WhatsAppConnectionController::class, 'index'])->name('connection');
    Route::get('/status', [WhatsAppConnectionController::class, 'status'])->name('status');
    Route::post('/prepare', [WhatsAppConnectionController::class, 'prepare'])->middleware('throttle:6,1')->name('prepare');
    Route::post('/automation', [WhatsAppConnectionController::class, 'automation'])->name('automation');
});

Route::middleware(['auth', 'auth.session', EnsureActiveUser::class, 'cache.headers:private;no_store;max_age=0'])->prefix('admin/messaging')->name('messaging.')->group(function (): void {
    Route::get('/', [MessagingController::class, 'index'])->name('index');
    Route::get('/simulator', [MessagingController::class, 'simulator'])->name('simulator');
    Route::post('/simulator', [MessagingController::class, 'simulate'])->name('simulate');
    Route::get('/{conversation}', [MessagingController::class, 'show'])->whereNumber('conversation')->name('show');
    Route::post('/{conversation}/assist', [MessagingController::class, 'assist'])->name('assist');
    Route::post('/{conversation}/register', [MessagingController::class, 'register'])->name('register');
    Route::post('/{conversation}/receipt', [MessagingController::class, 'receipt'])->name('receipt');
    Route::post('/{conversation}/reply', [MessagingController::class, 'reply'])->name('reply');
});
