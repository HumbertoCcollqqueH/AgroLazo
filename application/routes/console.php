<?php

use App\Jobs\SystemHealthProbe;
use App\Services\AggregationService;
use App\Services\CheckoutService;
use App\Services\OfferRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function (): void {
    Cache::store('redis')->put('system:scheduler:heartbeat', time(), 180);
})->name('system-heartbeat')->everyMinute();

Schedule::job(new SystemHealthProbe, 'default', 'redis')->name('system-queue-probe')->everyMinute();

Schedule::command('messaging:dispatch')->everyMinute()->withoutOverlapping();
Schedule::command('prices:refresh')->hourly()->withoutOverlapping()->runInBackground();
Schedule::command('prices:propose')->everyMinute()->withoutOverlapping();
Schedule::command('lots:consolidate --automatic')->everyFiveMinutes()->withoutOverlapping()->runInBackground();
Schedule::command('reports:prune')->hourly()->withoutOverlapping();
Schedule::call(fn () => app(OfferRegistry::class)->expire())->name('expire-offers')->hourly()->withoutOverlapping();
Schedule::call(fn () => app(AggregationService::class)->expire())->name('expire-lots')->hourly()->withoutOverlapping();
Schedule::call(fn () => app(CheckoutService::class)->expire())->name('expire-reservations')->everyMinute()->withoutOverlapping();
