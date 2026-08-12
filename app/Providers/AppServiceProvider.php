<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(30)->by(strtolower((string) $request->input('email')).'|'.$request->ip()));
        RateLimiter::for('dashboard-writes', fn (Request $request) => Limit::perMinute(60)->by(($request->user()?->id ?: $request->ip()).'|'.$request->route()?->getName()));
        RateLimiter::for('topup-submit', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));
    }
}
