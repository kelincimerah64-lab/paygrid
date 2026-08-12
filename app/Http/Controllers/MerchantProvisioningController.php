<?php

namespace App\Http\Controllers;

use App\Jobs\ProvisionMerchantOnGateway;
use App\Models\Merchant;
use Illuminate\Http\RedirectResponse;

class MerchantProvisioningController extends Controller
{
    public function retry(Merchant $merchant): RedirectResponse
    {
        abort_unless($merchant->approval_status === 'approved', 422);
        ProvisionMerchantOnGateway::dispatch($merchant->id);

        return back()->with('status', 'Provisioning merchant masuk queue untuk dicoba ulang.');
    }
}
