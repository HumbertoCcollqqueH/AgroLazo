<?php

use App\Http\Controllers\ReportController;
use App\Http\Middleware\EnsureActiveUser;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'auth.session', EnsureActiveUser::class, 'cache.headers:private;no_store;max_age=0'])->group(function (): void {
    Route::get('/reports', [ReportController::class, 'index'])->middleware('throttle:30,1')->name('reports.index');
    Route::post('/reports/exports', [ReportController::class, 'export'])->middleware('throttle:6,1')->name('reports.export');
    Route::get('/reports/exports/{export}', [ReportController::class, 'download'])->whereNumber('export')->middleware('throttle:20,1')->name('reports.download');
});
