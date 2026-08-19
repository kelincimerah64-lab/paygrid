<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('gateway:sync-transactions --max-pages=1 --page-size=25 --queue=live')
            ->everyFiveSeconds()
            ->withoutOverlapping(1)
            ->runInBackground();

        $schedule->command('gateway:sync-balances')
            ->everyThirtySeconds()
            ->withoutOverlapping(1)
            ->runInBackground();

        $schedule->command('gateway:sync-settlements')
            ->everyFiveMinutes()
            ->withoutOverlapping(4)
            ->runInBackground();

        $schedule->command('onboarding-links:expire')
            ->everyFiveMinutes()
            ->withoutOverlapping(4)
            ->runInBackground();

        $schedule->command('paygrid:queue-monitor')
            ->everyMinute()
            ->withoutOverlapping(1)
            ->runInBackground();

        $schedule->command('paygrid:maintenance-prune')
            ->hourly()
            ->withoutOverlapping(10)
            ->runInBackground();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->validateCsrfTokens(except: [
            'topup/*/regenerate/*',
        ]);
        $middleware->append(\App\Http\Middleware\SecurityHeadersMiddleware::class);
        $middleware->append(\App\Http\Middleware\ReadonlyUserMiddleware::class);
        $middleware->alias([
            'role' => \App\Http\Middleware\RoleMiddleware::class,
            'merchant.scope' => \App\Http\Middleware\MerchantScopeMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
