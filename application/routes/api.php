<?php

use App\Http\Controllers\MarketplaceController;
use App\Http\Controllers\Webhooks\EvolutionWebhookController;
use App\Http\Controllers\Webhooks\MetaWebhookController;
use App\Http\Controllers\Webhooks\SmsWebhookController;
use Illuminate\Support\Facades\Route;

Route::middleware(['throttle:120,1', 'cache.headers:no_store;max_age=0'])->prefix('v1/marketplace/lots')->name('api.marketplace.')->group(function (): void {
    Route::get('/', [MarketplaceController::class, 'apiIndex'])->name('index');
    Route::get('/{code}', [MarketplaceController::class, 'apiShow'])->where('code', 'L-[A-Z0-9]{8}')->name('show');
});

Route::prefix('v1/webhooks')->middleware('throttle:provider-webhooks')->group(function (): void {
    Route::get('whatsapp/meta', [MetaWebhookController::class, 'verify'])->name('webhooks.meta.verify');
    Route::post('whatsapp/meta', [MetaWebhookController::class, 'receive'])->name('webhooks.meta.receive');
    Route::post('whatsapp/evolution', EvolutionWebhookController::class)->name('webhooks.evolution');
    Route::post('sms/inbound', [SmsWebhookController::class, 'receive'])->name('webhooks.sms.inbound');
    Route::post('sms/status', [SmsWebhookController::class, 'status'])->name('webhooks.sms.status');
});
