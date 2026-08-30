<?php

namespace App\Http\Controllers;

use App\Models\Merchant;
use App\Models\MerchantRegistration;
use App\Models\Agent;
use App\Notifications\MerchantRegistrationSubmittedToMa;
use App\Jobs\ProvisionMerchantOnGateway;
use App\Rules\FeeMenuRatesAboveFloor;
use App\Services\AuditLogService;
use App\Services\FeeMenuCatalog;
use App\Services\FeeSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MerchantRegistrationWorkflowController extends Controller
{
    public function submit(Request $request, MerchantRegistration $registration, AuditLogService $audit): RedirectResponse
    {
        if ($request->user()?->role === 'agent') {
            $agent = Agent::query()
                ->where('code', $request->user()->username)
                ->orWhere('email', $request->user()->email)
                ->firstOrFail();
            abort_unless((int) $registration->agent_id === (int) $agent->id, 403);
        }
        abort_unless(in_array($registration->status, ['draft', 'pending_agent'], true), 422);
        $before = $registration->only(['status', 'submitted_to_ma_at']);
        $registration->update(['status' => 'pending_ma', 'submitted_to_ma_at' => now()]);
        $ma = $registration->agent?->ma;
        if ($ma) {
            $ma->notify(new MerchantRegistrationSubmittedToMa($registration->fresh(['agent'])));
        }
        $audit->record('merchant_registration.submitted_to_ma', $registration, $before, $registration->only(array_keys($before)));

        return back()->with('status', 'Request toko berhasil dikirim ke MA.');
    }

    public function approve(Request $request, MerchantRegistration $registration, AuditLogService $audit, FeeSyncService $feeSync): RedirectResponse
    {
        $this->authorizeMaRegistration($request, $registration);
        abort_unless(in_array($registration->status, ['pending_ma', 'pending_agent'], true), 422);
        $feeMenus = app(FeeMenuCatalog::class);
        $typeCategory = $feeMenus->typeCategory((string) ($request->input('merchant_type') ?? $registration->merchant_type));
        $rates = $feeMenus->normalizeRates((array) $request->input('fee_menu_rates', []), 'merchant');
        $request->merge(['fee_menu_rates' => $rates]);
        $data = $request->validate([
            'gateway' => ['nullable', 'in:hilogate,alpha,artageto,kingspay'],
            'merchant_type' => ['nullable', 'in:cm,script'],
            'engine_type' => [Rule::requiredIf($typeCategory === 'engine'), 'nullable', 'in:sc,api'],
            'fee_menu_rates' => [new FeeMenuRatesAboveFloor('merchant', null)],
            'active_fee_menu' => ['required', Rule::in(array_keys(array_filter($rates)))],
            'payin_fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $payload = (array) ($registration->payload ?? []);
        $merchantMdr = (float) $rates[$data['active_fee_menu']];
        $payin = (float) ($data['payin_fee_percent'] ?? $payload['payin_fee_percent'] ?? 0);
        $settlementMethod = $feeMenus->settlementMethod($data['active_fee_menu']);

        $slug = Str::slug($registration->store_name);
        if (Merchant::query()->where('slug', $slug)->where('id', '<>', $registration->merchant_id)->exists()) {
            $slug .= '-'.Str::lower(Str::random(6));
        }

        $merchant = $registration->merchant_id
            ? Merchant::query()->findOrFail($registration->merchant_id)
            : new Merchant;
        $type = $data['merchant_type'] ?? $registration->merchant_type;
        $ownerAgent = $registration->agent_id ? Agent::query()->with('ma')->find($registration->agent_id) : null;
        $feeSnapshot = $ownerAgent
            ? $feeSync->snapshotFor($ownerAgent, $data['active_fee_menu'], $merchantMdr)
            : ['merchant_mdr_percent' => $merchantMdr, 'agent_fee_percent' => 0.0, 'ma_fee_percent' => 0.0];
        $merchant->fill([
            'agent_id' => $registration->agent_id,
            'slug' => $slug,
            'name' => $registration->store_name,
            'merchant_id' => $payload['merchant_id'] ?? $payload['hg_merchant_id'] ?? $merchant->merchant_id,
            'merchant_key' => $payload['merchant_key'] ?? $payload['hg_merchant_key'] ?? $merchant->merchant_key,
            'merchant_group_name' => $payload['merchant_group_name'] ?? $ownerAgent?->name ?? $merchant->merchant_group_name,
            'merchant_group_id' => $payload['merchant_group_id'] ?? $payload['hg_group_id'] ?? $ownerAgent?->hg_group_id ?? $merchant->merchant_group_id,
            'merchant_type' => $type,
            'engine_type' => $data['engine_type'] ?? $merchant->engine_type,
            'gateway' => $data['gateway'] ?? $registration->gateway,
            'approval_status' => 'approved',
            'topup_enabled' => $type === 'cm',
            'topup_url' => $type === 'cm' ? route('topup', ['merchant' => $slug]) : null,
            'minimum_topup_amount' => $payload['minimum_topup_amount'] ?? $merchant->minimum_topup_amount,
            'transaction_callback_url' => $payload['transaction_callback_url'] ?? url('/api/callbacks/hilogate/transaction'),
            'withdrawal_callback_url' => $payload['withdrawal_callback_url'] ?? $merchant->withdrawal_callback_url,
            'pic_email' => $payload['pic_email'] ?? $merchant->pic_email,
            'pic_telegram' => $payload['pic_telegram'] ?? $merchant->pic_telegram,
            'finance_email' => $payload['finance_email'] ?? $merchant->finance_email,
            'finance_telegram' => $payload['finance_telegram'] ?? $merchant->finance_telegram,
            'cs_email' => $payload['cs_email'] ?? $merchant->cs_email,
            'cs_telegram' => $payload['cs_telegram'] ?? $merchant->cs_telegram,
            'fee_menu' => $data['active_fee_menu'],
            'fee_menu_rates' => $data['fee_menu_rates'],
            'settlement_method' => $settlementMethod,
            'payin_fee_percent' => $payin,
            ...$feeSnapshot,
            'disbursement_fee_fixed' => (int) ($payload['disbursement_fee_fixed'] ?? $payload['withdrawal_fee'] ?? 0),
            'onboarding_payload' => $payload,
            'approved_at' => now(),
        ]);
        $merchant->save();

        $before = $registration->only(['status', 'merchant_id', 'approved_at']);
        $registration->update(['merchant_id' => $merchant->id, 'status' => 'approved', 'approved_at' => now()]);
        $audit->record('merchant_registration.approved', $registration, $before, $registration->only(array_keys($before)));
        $audit->record('merchant.created_from_registration', $merchant, null, $merchant->only(['agent_id', 'slug', 'name', 'gateway', 'merchant_type', 'approval_status']));
        if ($merchant->gateway === 'hilogate' && config('paygrid.gateway.hilogate.onboarding_email') && config('paygrid.gateway.hilogate.onboarding_password')) {
            ProvisionMerchantOnGateway::dispatch($merchant->id);
        }

        return back()->with('status', 'Merchant berhasil diapprove di PayGrid.');
    }

    public function reject(Request $request, MerchantRegistration $registration, AuditLogService $audit): RedirectResponse
    {
        $this->authorizeMaRegistration($request, $registration);
        abort_unless(in_array($registration->status, ['pending_ma', 'pending_agent'], true), 422);
        $before = $registration->only(['status', 'approved_at']);
        $registration->update(['status' => 'rejected', 'approved_at' => null]);
        $audit->record('merchant_registration.rejected', $registration, $before, $registration->only(array_keys($before)));

        return back()->with('status', 'Request merchant ditolak.');
    }

    private function authorizeMaRegistration(Request $request, MerchantRegistration $registration): void
    {
        if ($request->user()?->role === 'superadmin') {
            return;
        }

        $registration->loadMissing('agent');
        abort_unless($request->user()?->role === 'ma' && (int) $registration->agent?->ma_user_id === (int) $request->user()->id, 403);
    }
}
