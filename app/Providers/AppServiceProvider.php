<?php

namespace App\Providers;

use App\Models\GatewaySyncLog;
use App\Models\SupportTicket;
use App\Models\TopupRequest;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
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
        if (! $this->app->environment('local', 'testing')) {
            URL::forceScheme('https');
        }

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(30)->by(strtolower((string) $request->input('email')).'|'.$request->ip()));
        RateLimiter::for('dashboard-writes', fn (Request $request) => Limit::perMinute(60)->by(($request->user()?->id ?: $request->ip()).'|'.$request->route()?->getName()));
        RateLimiter::for('topup-submit', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));
        RateLimiter::for('topup-public', function (Request $request) {
            $topup = $request->route()?->parameter('topupRequest');
            $token = is_object($topup) ? ($topup->public_token ?? $topup->getKey()) : $topup;

            return Limit::perMinute(120)->by($request->ip().'|'.(string) $token);
        });

        View::composer('layouts.paygrid', function ($view): void {
            $view->with('paygridOps', $this->paygridOpsPayload($view->getData()));
        });
    }

    private function paygridOpsPayload(array $viewData): array
    {
        $user = auth()->user();
        $merchant = $viewData['merchant'] ?? null;
        $merchantId = $merchant?->id ?: $user?->merchant_id;
        $globalRoles = ['ma', 'superadmin', 'cs_pusat'];
        $canSeeGlobal = $user && in_array($user->role, $globalRoles, true);
        $scopeKey = $canSeeGlobal ? 'global' : ($merchantId ? 'merchant-'.$merchantId : 'health-only');

        return Cache::remember('paygrid:ops-strip:'.$scopeKey, now()->addSeconds(5), function () use ($canSeeGlobal, $merchantId): array {
            try {
                $now = CarbonImmutable::now('Asia/Jakarta');
                $oldestJob = DB::table('jobs')
                    ->whereIn('queue', ['default', 'live', 'backfill'])
                    ->whereNull('reserved_at')
                    ->where('available_at', '<=', time())
                    ->min('created_at');
                $queueLag = $oldestJob ? max(0, time() - (int) $oldestJob) : 0;
                $latestPull = GatewaySyncLog::query()
                    ->where('direction', 'pull')
                    ->when(! $canSeeGlobal && $merchantId, fn ($query) => $query->where('merchant_id', $merchantId))
                    ->when(! $canSeeGlobal && ! $merchantId, fn ($query) => $query->whereRaw('1 = 0'))
                    ->latest('finished_at')
                    ->first();
                $latestPullAt = $latestPull?->finished_at ? CarbonImmutable::parse($latestPull->finished_at)->timezone('Asia/Jakarta') : null;
                $latestPullAge = $latestPullAt ? $latestPullAt->diffInSeconds($now, false) : null;

                $failedJobs = $canSeeGlobal
                    ? DB::table('failed_jobs')
                        ->where('failed_at', '>=', now()->subMinutes(15))
                        ->where('exception', 'not like', '%SQLSTATE[40001]%')
                        ->where('exception', 'not like', '%Deadlock found%')
                        ->count()
                    : GatewaySyncLog::query()
                        ->where('direction', 'pull')
                        ->where('status', 'failed')
                        ->when($merchantId, fn ($query) => $query->where('merchant_id', $merchantId))
                        ->when(! $merchantId, fn ($query) => $query->whereRaw('1 = 0'))
                        ->where('finished_at', '>=', now()->subMinutes(5))
                        ->count();

                $items = [];
                $alerts = [];
                $healthy = $queueLag < 60 && $failedJobs === 0 && ($latestPullAge === null || $latestPullAge < 90);

                $items[] = [
                    'tone' => $healthy ? 'ok' : 'warn',
                    'text' => 'Live sync '.($healthy ? 'normal' : 'perlu pantau').' | queue '.$queueLag.'s | failed '.$failedJobs,
                ];

                if ($latestPullAt) {
                    $items[] = ['tone' => 'info', 'text' => 'Gateway pull terakhir '.$latestPullAge.'s lalu'];
                }

                $failedQuery = GatewaySyncLog::query()
                    ->with('merchant')
                    ->where('direction', 'pull')
                    ->where('status', 'failed')
                    ->where('finished_at', '>=', now()->subMinutes(5))
                    ->whereHas('merchant', fn ($query) => $query->where('merchant_type', 'cm')->where('topup_enabled', true))
                    ->when(! $canSeeGlobal && $merchantId, fn ($query) => $query->where('merchant_id', $merchantId))
                    ->latest('finished_at')
                    ->limit(3)
                    ->get();

                foreach ($failedQuery as $log) {
                    $alerts[] = [
                        'tone' => 'danger',
                        'text' => ($log->merchant?->name ?: 'Merchant').' gateway '.($log->http_status ?: 'error'),
                    ];
                }

                $trxQuery = TopupRequest::query()
                    ->with('merchant')
                    ->when(! $canSeeGlobal && $merchantId, fn ($query) => $query->where('merchant_id', $merchantId))
                    ->when(! $canSeeGlobal && ! $merchantId, fn ($query) => $query->whereRaw('1 = 0'))
                    ->latest('created_at')
                    ->limit(4)
                    ->get();

                foreach ($trxQuery as $trx) {
                    $created = $trx->created_at ? CarbonImmutable::parse($trx->created_at)->timezone('Asia/Jakarta') : null;
                    $age = $created ? $created->diffInSeconds($now, false).'s lalu' : 'baru';
                    $items[] = [
                        'tone' => $trx->status === 'success' ? 'ok' : ($trx->status === 'pending' ? 'warn' : 'info'),
                        'text' => ($trx->merchant?->name ?: 'Merchant').': '.number_format((int) $trx->amount, 0, ',', '.').' '.$trx->status.' '.$age,
                    ];
                }

                $ticketQuery = SupportTicket::query()
                    ->with('merchant')
                    ->when(! $canSeeGlobal && $merchantId, fn ($query) => $query->where('merchant_id', $merchantId))
                    ->when(! $canSeeGlobal && ! $merchantId, fn ($query) => $query->whereRaw('1 = 0'))
                    ->latest('created_at')
                    ->limit(2)
                    ->get();

                foreach ($ticketQuery as $ticket) {
                    $items[] = [
                        'tone' => 'info',
                        'text' => 'Ticket '.$ticket->ticket_no.' dari '.($ticket->merchant?->name ?: 'merchant').' status '.$ticket->status,
                    ];
                }

                return [
                    'healthy' => $healthy,
                    'queueLag' => $queueLag,
                    'failedJobs' => $failedJobs,
                    'latestPullAge' => $latestPullAge,
                    'items' => array_slice(array_merge($alerts, $items), 0, 10),
                    'alerts' => $alerts,
                ];
            } catch (\Throwable) {
                return ['healthy' => false, 'queueLag' => null, 'failedJobs' => null, 'latestPullAge' => null, 'items' => [], 'alerts' => []];
            }
        });
    }
}
