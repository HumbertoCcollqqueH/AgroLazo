<?php

use App\Http\Controllers\Admin\CatalogController;
use App\Http\Middleware\EnsureActiveUser;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'auth.session', EnsureActiveUser::class])->prefix('admin/catalog')->name('catalog.')->group(function (): void {
    Route::post('/normalize', [CatalogController::class, 'normalize'])->name('normalize');
    Route::get('/{catalog}', [CatalogController::class, 'index'])->name('index');
    Route::get('/{catalog}/create', [CatalogController::class, 'create'])->name('create');
    Route::post('/{catalog}', [CatalogController::class, 'store'])->name('store');
    Route::get('/{catalog}/{id}/edit', [CatalogController::class, 'edit'])->whereNumber('id')->name('edit');
    Route::patch('/{catalog}/{id}', [CatalogController::class, 'update'])->whereNumber('id')->name('update');
});
