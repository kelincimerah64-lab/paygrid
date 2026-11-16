<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\AgentOnboardingLink;
use App\Models\Merchant;
use App\Models\MerchantRegistration;
use App\Models\User;
use App\Notifications\MerchantRegistrationSubmittedToMa;
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

    public function tokenFormMagnetopay(AgentOnboardingLink $link): View
    {
        return view('paygrid.onboarding-form-magnetopay', [
            'link' => $link,
        ]);
    }

    public function tokenStoreMagnetopay(Request $request, AgentOnboardingLink $link, AuditLogService $audit): RedirectResponse
    {
        $data = $request->validate([
            'store_name' => ['required', 'string', 'max:120'],
            'engine_name' => ['nullable', 'string', 'max:120'],
        ]);
        $ipAddress = $request->ip();

        // Settlement/fee fields are still MA-only (set at approval, not by
        // the public submitter) - stripped here too so they can't be
        // smuggled in via a raw POST that bypasses the form's hidden fields.
        // IP whitelist IS allowed through from the public submitter now - the
        // MA still reviews/edits it at approval time (ma.blade.php pre-fills
        // the approval form's IP fields from this payload), it just no
        // longer requires the MA to type it in from scratch.
        $payload = array_diff_key(
            $this->normalizeMultiSlotFields($request->except('_token')),
            array_flip([
                'va_settlement_type', 'is_whitelisted',
                'transaction_payment_gateway', 'withdrawal_payment_gateway', 'va_payment_gateway',
                'magnetopay_fee_1_percent', 'magnetopay_fee_2_percent', 'magnetopay_fee_3_percent',
                'magnetopay_fee_va_percent', 'magnetopay_fee_withdrawal_fixed', 'magnetopay_fee_withdrawal_percent',
            ]),
        );

        // "Engine Selection" (API/Script/CM) picks merchant_type: CM has no
        // further sub-choice and isn't an engine at all, so it maps straight
        // to merchant_type=cm with no engine_type. API/Script both stay
        // merchant_type=script (the existing cm/script distinction), with
        // engine_type recording which of the two.
        $engineType = (string) $request->input('engine_type', '');
        $merchantType = $engineType === 'cm' ? 'cm' : 'script';
        if ($merchantType === 'cm') {
            unset($payload['engine_type']);
        }

        $registration = DB::transaction(function () use ($link, $data, $payload, $merchantType, $ipAddress) {
            $lockedLink = AgentOnboardingLink::query()->whereKey($link->id)->lockForUpdate()->firstOrFail();

            if ($lockedLink->status === 'active' && $lockedLink->expires_at?->isPast()) {
                $lockedLink->update(['status' => 'expired']);
            }

            abort_unless($lockedLink->isUsable(), 410, 'Link onboarding sudah expired atau sudah pernah dipakai.');

            $registration = MerchantRegistration::query()->create([
                'agent_id' => $lockedLink->agent_id,
                'token' => (string) Str::uuid(),
                'ip_address' => $ipAddress,
                'store_name' => $data['store_name'],
                'engine_name' => $data['engine_name'] ?? null,
                'merchant_type' => $merchantType,
                'gateway' => 'magnetopay',
                'payload' => $payload + [
                    'agent_onboarding_link_id' => $lockedLink->id,
                    'recipient_email' => $lockedLink->recipient_email,
                    'recipient_telegram' => $lockedLink->recipient_telegram,
                ],
                'status' => 'pending_agent',
            ]);

            if ($lockedLink->reusable) {
                $lockedLink->update(['used_at' => now()]);
            } else {
                $lockedLink->update([
                    'merchant_registration_id' => $registration->id,
                    'status' => 'used',
                    'used_at' => now(),
                ]);
            }

            return $registration;
        });
        $audit->record('merchant_registration.created_from_agent_link', $registration, null, $registration->only([
            'agent_id', 'store_name', 'merchant_type', 'gateway', 'status',
        ]));

        return redirect()->route('merchant-registration.magnetopay.token-form', $link)->with('status', 'Data berhasil terkirim.');
    }

    public function store(Request $request, AuditLogService $audit, FeeMenuCatalog $feeMenus): RedirectResponse
    {
        $data = $request->validate($this->scriptAwareOnboardingRules($request, $feeMenus));

        $agent = Agent::query()->where('code', $request->input('agent_code', 'AG-EPC'))->firstOrFail();

        $registration = MerchantRegistration::query()->create([
            'agent_id' => $agent->id,
            'token' => (string) Str::uuid(),
            'store_name' => $data['store_name'],
            'engine_name' => $data['engine_name'] ?? null,
            'merchant_type' => $data['merchant_type'] ?? 'cm',
            'gateway' => $data['gateway'] ?? 'hilogate',
            'settlement_method' => isset($data['fee_menu']) ? $feeMenus->settlementMethod($data['fee_menu']) : null,
            'payload' => $this->normalizeMultiSlotFields($request->except('_token')),
            'status' => 'pending_agent',
        ]);
        $audit->record('merchant_registration.created', $registration, null, $registration->only([
            'agent_id', 'store_name', 'merchant_type', 'gateway', 'status',
        ]));

        return back()->with('status', 'Toko berhasil didaftarkan dan masuk ke request agen.');
    }

    public function tokenStore(Request $request, AgentOnboardingLink $link, AuditLogService $audit, FeeMenuCatalog $feeMenus): RedirectResponse
    {
        $data = $request->validate($this->scriptAwareOnboardingRules($request, $feeMenus));

        $payload = $this->normalizeMultiSlotFields($request->except('_token'));
        $registration = DB::transaction(function () use ($link, $data, $payload, $feeMenus) {
            $lockedLink = AgentOnboardingLink::query()->whereKey($link->id)->lockForUpdate()->firstOrFail();

            if ($lockedLink->status === 'active' && $lockedLink->expires_at?->isPast()) {
                $lockedLink->update(['status' => 'expired']);
            }

            abort_unless($lockedLink->isUsable(), 410, 'Link onboarding sudah expired atau sudah pernah dipakai.');

            // A link with no agent_id (the universal hidden onboarding link)
            // has no agent to review it first - it skips straight to pending_ma
            // instead of pending_agent, since nothing would ever call submit()
            // on its behalf.
            $isUniversal = $lockedLink->agent_id === null;

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
                'status' => $isUniversal ? 'pending_ma' : 'pending_agent',
                'submitted_to_ma_at' => $isUniversal ? now() : null,
            ]);

            if ($lockedLink->reusable) {
                // MA-generated links are 1-per-agent and permanent (see
                // MaController::storeOnboardingLink) - stay active after a
                // submission so the same link can be used again, instead of
                // linking to a single registration like a one-shot link does.
                $lockedLink->update(['used_at' => now()]);
            } else {
                $lockedLink->update([
                    'merchant_registration_id' => $registration->id,
                    'status' => 'used',
                    'used_at' => now(),
                ]);
            }

            return $registration;
        });
        $audit->record('merchant_registration.created_from_agent_link', $registration, null, $registration->only([
            'agent_id', 'store_name', 'merchant_type', 'gateway', 'status',
        ]));

        if ($registration->status === 'pending_ma') {
            User::query()->where('role', 'ma')->where('is_demo', false)->get()->each(
                fn ($ma) => $ma->notify(new MerchantRegistrationSubmittedToMa($registration->fresh(['agent'])))
            );
        }

        $statusMessage = $link->reusable
            ? 'Form berhasil terkirim.'
            : 'Form berhasil terkirim. Link ini sudah tidak berlaku lagi.';

        return redirect()->route('merchant-registration.token-form', $link)->with('status', $statusMessage);
    }

    public function topup(?Merchant $merchant = null): View
    {
        return view('paygrid.topup', [
            'merchant' => $merchant ?? Merchant::query()->where('merchant_type', 'cm')->first(),
        ]);
    }

    /**
     * Callback Alpha / IP PG / IP Alpha only appear on the public onboarding
     * form (and are only required) when "Script" is checked (engine_type=sc)
     * - this mirrors that client-side toggle server-side so the requirement
     * can't be bypassed by posting directly to the endpoint.
     */
    private function scriptAwareOnboardingRules(Request $request, FeeMenuCatalog $feeMenus): array
    {
        $isScript = $request->input('engine_type') === 'sc';
        $atLeastOneIpWhenScript = function (string $attribute, $value, \Closure $fail) use ($isScript) {
            if ($isScript && empty(array_filter((array) $value, fn ($v) => trim((string) $v) !== ''))) {
                $fail('Minimal 1 IP wajib diisi untuk tipe Script.');
            }
        };

        return [
            'store_name' => ['required', 'string', 'max:120'],
            'engine_name' => ['nullable', 'string', 'max:120'],
            'merchant_type' => ['nullable', 'in:cm,script'],
            'gateway' => ['nullable', 'in:hilogate,alpha,artageto,kingspay,magnetopay'],
            'fee_menu' => ['nullable', Rule::in(array_keys($this->feeMenuOptionsExcludingBased($feeMenus)))],
            'engine_type' => ['nullable', 'in:sc,api'],
            'callback_alpha_url' => [Rule::requiredIf($isScript), 'nullable', 'url', 'max:255'],
            'ip_pg' => [Rule::requiredIf($isScript), 'nullable', 'array', $atLeastOneIpWhenScript],
            'ip_pg.*' => ['nullable', 'string', 'max:45'],
            'ip_alpha' => [Rule::requiredIf($isScript), 'nullable', 'array', $atLeastOneIpWhenScript],
            'ip_alpha.*' => ['nullable', 'string', 'max:45'],
        ];
    }

    /**
     * The onboarding form collects ip_dashboard/ip_finance/api_ip_whitelist as
     * several numbered input slots (ip_dashboard[], etc.) instead of one
     * comma-separated field. Join each back into a single comma-separated
     * string here so the stored payload shape - and everything downstream
     * that reads it (MerchantRegistration::detailRows(), the MA approval
     * view, the PDF export) - stays exactly as it was before.
     */
    private function normalizeMultiSlotFields(array $payload): array
    {
        foreach (['ip_dashboard', 'ip_finance', 'api_ip_whitelist', 'ip_pg', 'ip_alpha', 'support_telegram_ids', 'cs_email', 'finance_role_email'] as $field) {
            if (is_array($payload[$field] ?? null)) {
                $payload[$field] = implode(', ', array_filter(array_map('trim', $payload[$field]), fn ($v) => $v !== ''));
            }
        }

        return $payload;
    }

    private function feeMenuOptionsExcludingBased(FeeMenuCatalog $feeMenus): array
    {
        $options = $feeMenus->optionsFor('merchant');
        unset($options['based']);

        return $options;
    }
}
