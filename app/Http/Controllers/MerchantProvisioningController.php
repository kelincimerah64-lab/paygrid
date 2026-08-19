<?php

namespace App\Http\Controllers;

use App\Jobs\ProvisionMerchantOnGateway;
use App\Models\Merchant;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;

class MerchantProvisioningController extends Controller
{
    public function retry(Request $request, Merchant $merchant): RedirectResponse
    {
        $this->authorizeMerchant($request, $merchant);
        abort_unless($merchant->approval_status === 'approved', 422);
        ProvisionMerchantOnGateway::dispatch($merchant->id);

        return back()->with('status', 'Provisioning merchant masuk queue untuk dicoba ulang.');
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
