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

        $schedule->command('gateway:sync-withdrawals')
            ->everyFiveMinutes()
            ->withoutOverlapping(4)
            ->runInBackground();

        $schedule->command('onboarding-links:expire')
            ->everyFiveMinutes()
            ->withoutOverlapping(4)
            ->runInBackground();

        $schedule->command('tickets:auto-create-pending')
            ->everyMinute()
            ->withoutOverlapping(5)
            ->runInBackground();

        $schedule->command('wa-tickets:remind-unclaimed')
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
        // Restricted to Cloudflare's published edge ranges (cloudflare.com/ips) -
        // NOT '*'. The app sits behind Cloudflare, but the origin also accepts
        // direct connections, so trusting every peer let anyone who hits the
        // origin IP directly forge X-Forwarded-For and impersonate a trusted
        // source (e.g. the Hilogate-callback IP allowlist, login rate-limit key).
        // Only requests actually relayed through a listed Cloudflare IP have
        // their forwarded headers honored; direct-to-origin traffic falls back
        // to the real, unspoofable socket peer address.
        // 15.232.137.74/32 is the main PayGrid VPS, which reverse-proxies
        // /mobile/* (and its static assets) to the separate mobile-app VPS -
        // this same codebase runs on both, so the mobile VPS needs to trust
        // that one specific upstream to correctly read X-Forwarded-Proto/-For
        // from it (e.g. for SESSION_SECURE_COOKIE); harmless on the main VPS
        // itself, which never receives a request forwarded from its own IP.
        $middleware->trustProxies(at: [
            '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
            '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
            '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
            '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
            '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
            '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
            '15.232.137.74/32',
        ]);
        $middleware->validateCsrfTokens(except: [
            'topup/*/regenerate/*',
        ]);
        $middleware->append(\App\Http\Middleware\SecurityHeadersMiddleware::class);
        $middleware->append(\App\Http\Middleware\ReadonlyUserMiddleware::class);
        $middleware->alias([
            'role' => \App\Http\Middleware\RoleMiddleware::class,
            'merchant.scope' => \App\Http\Middleware\MerchantScopeMiddleware::class,
        ]);
        $middleware->redirectGuestsTo(fn ($request) => $request->is('mobile/*') ? route('mobile.login') : route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
