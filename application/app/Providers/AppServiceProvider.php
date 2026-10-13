<?php

namespace App\Providers;

use App\Models\Buyer;
use App\Models\Producer;
use App\Models\User;
use App\Policies\BuyerPolicy;
use App\Policies\ProducerPolicy;
use App\Policies\UserPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('provider-webhooks', fn (Request $request) => Limit::perMinute((int) config('security.webhook_limit', 300))->by(hash_hmac('sha256', (string) $request->ip(), (string) config('app.key'))));
        foreach (['manage-commerce', 'manage-pricing', 'manage-traceability'] as $ability) {
            Gate::define($ability, fn (User $user): bool => $user->hasPermission($ability));
        }
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Buyer::class, BuyerPolicy::class);
        Gate::policy(Producer::class, ProducerPolicy::class);

        foreach (['manage-users', 'view-operations', 'view-buyer-area', 'approve-buyers', 'confirm-payments', 'view-catalogs', 'manage-catalogs', 'view-producers', 'manage-producers', 'verify-producers', 'view-offers', 'manage-offers', 'manage-messaging', 'view-lots', 'manage-lots'] as $ability) {
            Gate::define($ability, fn (User $user): bool => $user->hasPermission($ability));
        }

        Gate::define('place-orders', fn (User $user): bool => $user->hasPermission('place-orders')
            && $user->buyer()->where('status', 'approved')->exists());
    }
}
