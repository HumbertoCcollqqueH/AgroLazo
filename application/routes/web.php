<?php

require __DIR__.'/commerce.php';
require __DIR__.'/fulfillment.php';
require __DIR__.'/payments.php';
require __DIR__.'/traceability.php';
require __DIR__.'/reports.php';
require __DIR__.'/portal.php';

use App\Http\Controllers\Admin\MessagingController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SystemStatusController;
use App\Http\Middleware\EnsureActiveUser;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->middleware('cache.headers:private;no_store;max_age=0')->name('home');
Route::get('/app', [HomeController::class, 'mobile'])->middleware('cache.headers:private;no_store;max_age=0')->name('mobile.home');
Route::get('/dev/status', SystemStatusController::class)->name('dev.status');

Route::get('/dashboard', [ReportController::class, 'dashboard'])
    ->middleware(['auth', 'auth.session', EnsureActiveUser::class])->name('dashboard');

require __DIR__.'/auth.php';
require __DIR__.'/catalog.php';
require __DIR__.'/producers.php';
require __DIR__.'/offers.php';
require __DIR__.'/messaging.php';
require __DIR__.'/lots.php';

Route::get('/dev/message-simulator', [MessagingController::class, 'simulator'])
    ->middleware(['auth', 'auth.session', EnsureActiveUser::class, 'cache.headers:private;no_store;max_age=0'])
    ->name('dev.message-simulator');
