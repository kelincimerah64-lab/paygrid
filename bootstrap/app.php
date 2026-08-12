<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('gateway:sync-transactions')
            ->everyMinute()
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
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(\App\Http\Middleware\SecurityHeadersMiddleware::class);
        $middleware->alias([
            'role' => \App\Http\Middleware\RoleMiddleware::class,
            'merchant.scope' => \App\Http\Middleware\MerchantScopeMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
