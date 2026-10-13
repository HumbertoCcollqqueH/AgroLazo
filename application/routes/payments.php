<?php

use App\Http\Controllers\PaymentController;
use App\Http\Middleware\EnsureActiveUser;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'auth.session', EnsureActiveUser::class, 'cache.headers:private;no_store;max_age=0'])->prefix('payments')->name('payments.')->group(function (): void {
    Route::get('/methods', [PaymentController::class, 'methods'])->name('methods');
    Route::post('/methods/{method}', [PaymentController::class, 'configure'])->whereNumber('method')->name('methods.configure');
    Route::post('/orders/{order}', [PaymentController::class, 'declare'])->whereNumber('order')->name('declare');
    Route::post('/{payment}/proofs', [PaymentController::class, 'proof'])->whereNumber('payment')->name('proofs');
    Route::get('/proofs/{proof}', [PaymentController::class, 'download'])->whereNumber('proof')->name('proof');
    Route::post('/{payment}/review', [PaymentController::class, 'review'])->whereNumber('payment')->name('review');
    Route::post('/{payment}/refunds', [PaymentController::class, 'refund'])->whereNumber('payment')->name('refund');
    Route::post('/refunds/{refund}', [PaymentController::class, 'refundOutcome'])->whereNumber('refund')->name('refund.outcome');
    Route::post('/orders/{order}/settlements', [PaymentController::class, 'calculate'])->whereNumber('order')->name('settlements');
    Route::post('/settlements/{settlement}/approve', [PaymentController::class, 'approve'])->whereNumber('settlement')->name('settlements.approve');
    Route::post('/settlements/{settlement}/payouts', [PaymentController::class, 'payout'])->whereNumber('settlement')->name('payout');
    Route::post('/payouts/{payout}', [PaymentController::class, 'payoutOutcome'])->whereNumber('payout')->name('payout.outcome');
    Route::post('/orders/{order}/defer', [PaymentController::class, 'defer'])->whereNumber('order')->name('defer');
    Route::post('/orders/{order}/close', [PaymentController::class, 'close'])->whereNumber('order')->name('close');
});
