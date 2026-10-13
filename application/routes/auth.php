<?php

use App\Http\Controllers\Admin\BuyerApprovalController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\Auth\AccountController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredBuyerController;
use App\Http\Controllers\LocationLookupController;
use App\Http\Middleware\EnsureActiveUser;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:30,1');
    Route::get('/register', [RegisteredBuyerController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredBuyerController::class, 'store'])->middleware('throttle:10,1');
    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])->middleware('throttle:10,1')->name('password.store');
});

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->middleware('auth')->name('logout');

Route::middleware(['auth', 'auth.session', EnsureActiveUser::class])->group(function (): void {
    Route::post('/locations/reverse', LocationLookupController::class)->middleware('throttle:30,1')->name('locations.reverse');
    Route::patch('/account/delivery-address', [AccountController::class, 'deliveryAddress'])->middleware('throttle:10,1')->name('account.delivery-address');
    Route::get('/account', [AccountController::class, 'edit'])->name('account.edit');
    Route::patch('/account', [AccountController::class, 'update'])->middleware('throttle:10,1')->name('account.update');
});

Route::middleware(['auth', 'auth.session', EnsureActiveUser::class])->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/users', [UserManagementController::class, 'index'])->name('users.index');
    Route::get('/users/create', [UserManagementController::class, 'create'])->name('users.create');
    Route::post('/users', [UserManagementController::class, 'store'])->middleware('throttle:10,1')->name('users.store');
    Route::patch('/users/{user}', [UserManagementController::class, 'update'])->name('users.update');
    Route::post('/buyers/{buyer}/approve', [BuyerApprovalController::class, 'store'])->name('buyers.approve');
});
