<?php

namespace App\Http\Controllers;

use App\Models\Merchant;
use App\Services\AuditLogService;
use App\Services\GatewaySyncDispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;

class GatewaySyncRetryController extends Controller
{
    public function store(Request $request, Merchant $merchant, AuditLogService $audit, GatewaySyncDispatcher $dispatcher): RedirectResponse
    {
        $this->authorizeMerchant($request, $merchant);
        abort_unless($merchant->approval_status === 'approved', 422);
        $dispatched = $dispatcher->dispatch($merchant->id);
        $audit->record('gateway.sync_retry_requested', $merchant, null, ['merchant_id' => $merchant->id]);

        return back()->with('status', $dispatched ? 'Sync merchant masuk queue.' : 'Sync merchant masih berjalan, tidak dibuat queue duplikat.');
    }

    private function authorizeMerchant(Request $request, Merchant $merchant): void
    {
        if ($request->user()?->role === 'superadmin') {
            return;
        }

        $merchant->loadMissing('agent');
        abort_unless($request->user()?->role === 'ma' && (int) $merchant->agent?->ma_user_id === (int) $request->user()->id, 403);
    }
}
