<?php

namespace App\Http\Controllers;

use App\Jobs\SyncMerchantTransactions;
use App\Models\Merchant;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;

class GatewaySyncRetryController extends Controller
{
    public function store(Merchant $merchant, AuditLogService $audit): RedirectResponse
    {
        abort_unless($merchant->approval_status === 'approved', 422);
        SyncMerchantTransactions::dispatch($merchant->id);
        $audit->record('gateway.sync_retry_requested', $merchant, null, ['merchant_id' => $merchant->id]);

        return back()->with('status', 'Sync merchant masuk queue.');
    }
}
