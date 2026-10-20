<?php

use App\Http\Controllers\CoordinationController;
use App\Http\Controllers\ProducerPayoutProfileController;
use App\Http\Controllers\ProducerPortalController;
use App\Http\Middleware\EnsureActiveUser;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/register/producer', [ProducerPortalController::class, 'register'])->name('producer.register');
    Route::post('/register/producer', [ProducerPortalController::class, 'storeAccount'])->middleware('throttle:10,1');
});
Route::middleware(['auth', 'auth.session', EnsureActiveUser::class, 'cache.headers:private;no_store;max_age=0'])->group(function (): void {
    Route::get('/producer', [ProducerPortalController::class, 'home'])->name('producer.home');
    Route::get('/producer/profile', [ProducerPortalController::class, 'profile'])->name('producer.profile');
    Route::post('/producer/profile', [ProducerPortalController::class, 'saveProfile'])->middleware('throttle:10,1')->name('producer.profile.save');
    Route::get('/producer/offers/create', [ProducerPortalController::class, 'createOffer'])->name('producer.offers.create');
    Route::post('/producer/offers', [ProducerPortalController::class, 'storeOffer'])->middleware('throttle:10,1')->name('producer.offers.store');
    Route::get('/producer/offers/{offer}', [ProducerPortalController::class, 'showOffer'])->whereNumber('offer')->name('producer.offers.show');
    Route::get('/producer/offers/{offer}/edit', [ProducerPortalController::class, 'editOffer'])->whereNumber('offer')->name('producer.offers.edit');
    Route::patch('/producer/offers/{offer}', [ProducerPortalController::class, 'updateOffer'])->middleware('throttle:10,1')->name('producer.offers.update');
    Route::post('/producer/offers/{offer}/photos', [ProducerPortalController::class, 'uploadPhoto'])->middleware('throttle:10,1')->name('producer.offers.photos');
    Route::get('/producer/offers/{offer}/photos/{photo}', [ProducerPortalController::class, 'photo'])->name('producer.offers.photo');
    Route::post('/producer/offers/{offer}/agreements/{agreement}', [ProducerPortalController::class, 'decide'])->middleware('throttle:10,1')->name('producer.agreements.decide');
    Route::get('/producer/sales', [ProducerPortalController::class, 'sales'])->name('producer.sales');
    Route::get('/coordination', [CoordinationController::class, 'index'])->name('coordination.index');
    Route::get('/notifications', [CoordinationController::class, 'notifications'])->name('notifications.index');
    Route::get('/coordination/updates', [CoordinationController::class, 'updates'])->middleware('throttle:30,1,coordination-updates:')->name('coordination.updates');
    Route::get('/coordination/conversations', [CoordinationController::class, 'conversations'])->middleware('throttle:60,1,coordination-conversations:')->name('coordination.conversations');
    Route::post('/coordination', [CoordinationController::class, 'store'])->middleware('throttle:10,1,coordination-store:')->name('coordination.store');
    Route::get('/coordination/{thread}', [CoordinationController::class, 'show'])->whereNumber('thread')->name('coordination.show');
    Route::get('/coordination/{thread}/messages', [CoordinationController::class, 'messages'])->whereNumber('thread')->middleware('throttle:120,1,coordination-messages:')->name('coordination.messages');
    Route::post('/coordination/{thread}/seen', [CoordinationController::class, 'seen'])->whereNumber('thread')->middleware('throttle:120,1,coordination-seen:')->name('coordination.seen');
    Route::post('/coordination/{thread}', [CoordinationController::class, 'reply'])->middleware('throttle:60,1,coordination-reply:')->name('coordination.reply');
    Route::post('/notifications/{notification}/read', [CoordinationController::class, 'read'])->name('notifications.read');
    Route::post('/notifications/{notification}/open', [CoordinationController::class, 'openNotice'])->name('notifications.open');
    Route::post('/notifications/read-all', [CoordinationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('/admin/producers/{producer}/link-account', [ProducerPortalController::class, 'linkAccount'])->middleware('throttle:10,1')->name('producers.link-account');
    Route::post('/producer/payout-profile', [ProducerPayoutProfileController::class, 'save'])->middleware('throttle:10,1')->name('producer.payout-profile.save');
    Route::get('/producer/payout-profile/{profile}/qr', [ProducerPayoutProfileController::class, 'qr'])->whereNumber('profile')->middleware('throttle:60,1')->name('producer.payout-profile.qr');
});
