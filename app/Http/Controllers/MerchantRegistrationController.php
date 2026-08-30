<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\AgentOnboardingLink;
use App\Models\Merchant;
use App\Models\MerchantRegistration;
use App\Services\AuditLogService;
use App\Services\FeeMenuCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MerchantRegistrationController extends Controller
{
    public function form(Request $request, FeeMenuCatalog $feeMenus): View
    {
        return view('paygrid.onboarding-form', [
            'agent' => Agent::query()->where('code', $request->query('agent', 'AG-EPC'))->first(),
            'link' => null,
            'feeMenuOptions' => $this->feeMenuOptionsExcludingBased($feeMenus),
        ]);
    }

    public function tokenForm(AgentOnboardingLink $link, FeeMenuCatalog $feeMenus): View
    {
        return view('paygrid.onboarding-form', [
            'agent' => $link->agent,
            'link' => $link,
            'feeMenuOptions' => $this->feeMenuOptionsExcludingBased($feeMenus),
        ]);
    }

    public function store(Request $request, AuditLogService $audit, FeeMenuCatalog $feeMenus): RedirectResponse
    {
        $data = $request->validate([
            'store_name' => ['required', 'string', 'max:120'],
            'engine_name' => ['nullable', 'string', 'max:120'],
            'merchant_type' => ['nullable', 'in:cm,script'],
            'gateway' => ['nullable', 'in:hilogate,alpha,artageto,kingspay'],
            'fee_menu' => ['nullable', Rule::in(array_keys($this->feeMenuOptionsExcludingBased($feeMenus)))],
        ]);

        $agent = Agent::query()->where('code', $request->input('agent_code', 'AG-EPC'))->firstOrFail();

        $registration = MerchantRegistration::query()->create([
            'agent_id' => $agent->id,
            'token' => (string) Str::uuid(),
            'store_name' => $data['store_name'],
            'engine_name' => $data['engine_name'] ?? null,
            'merchant_type' => $data['merchant_type'] ?? 'cm',
            'gateway' => $data['gateway'] ?? 'hilogate',
            'settlement_method' => isset($data['fee_menu']) ? $feeMenus->settlementMethod($data['fee_menu']) : null,
            'payload' => $request->except('_token'),
            'status' => 'pending_agent',
        ]);
        $audit->record('merchant_registration.created', $registration, null, $registration->only([
            'agent_id', 'store_name', 'merchant_type', 'gateway', 'status',
        ]));

        return back()->with('status', 'Toko berhasil didaftarkan dan masuk ke request agen.');
    }

    public function tokenStore(Request $request, AgentOnboardingLink $link, AuditLogService $audit, FeeMenuCatalog $feeMenus): RedirectResponse
    {
        $data = $request->validate([
            'store_name' => ['required', 'string', 'max:120'],
            'engine_name' => ['nullable', 'string', 'max:120'],
            'merchant_type' => ['nullable', 'in:cm,script'],
            'gateway' => ['nullable', 'in:hilogate,alpha,artageto,kingspay'],
            'fee_menu' => ['nullable', Rule::in(array_keys($this->feeMenuOptionsExcludingBased($feeMenus)))],
        ]);

        $payload = $request->except('_token');
        $registration = DB::transaction(function () use ($link, $data, $payload, $feeMenus) {
            $lockedLink = AgentOnboardingLink::query()->whereKey($link->id)->lockForUpdate()->firstOrFail();

            if ($lockedLink->status === 'active' && $lockedLink->expires_at?->isPast()) {
                $lockedLink->update(['status' => 'expired']);
            }

            abort_unless($lockedLink->isUsable(), 410, 'Link onboarding sudah expired atau sudah pernah dipakai.');

            $registration = MerchantRegistration::query()->create([
                'agent_id' => $lockedLink->agent_id,
                'token' => (string) Str::uuid(),
                'store_name' => $data['store_name'],
                'engine_name' => $data['engine_name'] ?? null,
                'merchant_type' => $data['merchant_type'] ?? 'cm',
                'gateway' => $data['gateway'] ?? 'hilogate',
                'settlement_method' => isset($data['fee_menu']) ? $feeMenus->settlementMethod($data['fee_menu']) : null,
                'payload' => $payload + [
                    'agent_onboarding_link_id' => $lockedLink->id,
                    'recipient_email' => $lockedLink->recipient_email,
                    'recipient_telegram' => $lockedLink->recipient_telegram,
                ],
                'status' => 'pending_agent',
            ]);

            $lockedLink->update([
                'merchant_registration_id' => $registration->id,
                'status' => 'used',
                'used_at' => now(),
            ]);

            return $registration;
        });
        $audit->record('merchant_registration.created_from_agent_link', $registration, null, $registration->only([
            'agent_id', 'store_name', 'merchant_type', 'gateway', 'status',
        ]));

        return redirect()->route('merchant-registration.token-form', $link)->with('status', 'Data berhasil dikirim. Link ini sudah expired dan tidak bisa dipakai lagi.');
    }

    public function topup(?Merchant $merchant = null): View
    {
        return view('paygrid.topup', [
            'merchant' => $merchant ?? Merchant::query()->where('merchant_type', 'cm')->first(),
        ]);
    }

    private function feeMenuOptionsExcludingBased(FeeMenuCatalog $feeMenus): array
    {
        $options = $feeMenus->optionsFor('merchant');
        unset($options['based']);

        return $options;
    }
}
