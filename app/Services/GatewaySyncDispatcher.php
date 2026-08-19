<?php

namespace App\Services;

use App\Jobs\SyncMerchantTransactions;
use App\Jobs\BackfillMerchantTransactions;
use Illuminate\Support\Facades\Cache;

class GatewaySyncDispatcher
{
    public function dispatch(int $merchantId, array $filters = [], string $queue = 'default'): bool
    {
        if (Cache::has($this->cooldownKey($merchantId))) {
            return false;
        }

        $key = 'paygrid:gateway-sync:queued-or-running:'.$merchantId;

        if (! Cache::add($key, true, now()->addMinutes(2))) {
            return false;
        }

        SyncMerchantTransactions::dispatch($merchantId, $filters)->onQueue($queue);

        return true;
    }

    public function release(int $merchantId): void
    {
        Cache::forget('paygrid:gateway-sync:queued-or-running:'.$merchantId);
    }

    public function coolDown(int $merchantId, int $seconds): void
    {
        Cache::put($this->cooldownKey($merchantId), true, now()->addSeconds($seconds));
    }

    private function cooldownKey(int $merchantId): string
    {
        return 'paygrid:gateway-sync:cooldown:'.$merchantId;
    }

    public function dispatchBackfill(int $merchantId, ?string $date = null): bool
    {
        $key = 'paygrid:gateway-backfill:queued-or-running:'.$merchantId;

        if (! Cache::add($key, true, now()->addMinutes(10))) {
            return false;
        }

        BackfillMerchantTransactions::dispatch($merchantId, $date)->onQueue('backfill');

        return true;
    }

    public function releaseBackfill(int $merchantId): void
    {
        Cache::forget('paygrid:gateway-backfill:queued-or-running:'.$merchantId);
    }
}
