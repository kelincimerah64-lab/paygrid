<?php

namespace App\Http\Controllers;

use App\Models\Merchant;
use App\Models\MerchantRegistration;
use App\Models\Agent;
use App\Models\User;
use App\Notifications\MerchantRegistrationSubmittedToMa;
use App\Jobs\ProvisionMerchantOnGateway;
use App\Rules\ExactlyOneFeeMenuFilled;
use App\Rules\FeeMenuRatesAboveFloor;
use App\Rules\FeeMenuRatesAboveReference;
use App\Services\AuditLogService;
use App\Services\FeeMenuCatalog;
use App\Services\FeeSyncService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MerchantRegistrationWorkflowController extends Controller
{
    public const MAX_REVISIONS = 3;

    public function submit(Request $request, MerchantRegistration $registration, AuditLogService $audit): RedirectResponse
    {
        if ($request->user()?->role === 'agent') {
            $agent = Agent::query()
                ->where('code', $request->user()->username)
                ->orWhere('email', $request->user()->email)
                ->firstOrFail();
            abort_unless((int) $registration->agent_id === (int) $agent->id, 403);
        }
        abort_unless(in_array($registration->status, ['draft', 'pending_agent', 'rejected'], true), 422);
        $wasRejected = $registration->status === 'rejected';
        abort_if($wasRejected && $registration->revision_count >= self::MAX_REVISIONS, 422);
        $before = $registration->only(['status', 'submitted_to_ma_at', 'revision_count']);
        $registration->update([
            'status' => 'pending_ma',
            'submitted_to_ma_at' => now(),
            'revision_count' => $wasRejected ? $registration->revision_count + 1 : $registration->revision_count,
        ]);
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
        $agentRates = (array) ($registration->agent?->fee_menu_rates ?? []);
        $request->merge(collect(['disbursement_fee_percent', 'marketing_fee_percent', 'ma_fee_percent', 'agent_fee_percent'])->mapWithKeys(fn ($field) => $request->has($field) ? [$field => str_replace(',', '.', (string) $request->input($field))] : [])->all());
        $data = $request->validate([
            'gateway' => ['nullable', 'in:hilogate,alpha,artageto,kingspay,magnetopay'],
            'merchant_type' => ['nullable', 'in:cm,script'],
            'engine_type' => [Rule::requiredIf($typeCategory === 'engine'), 'nullable', 'in:sc,api'],
            'admin_email' => ['required', 'email', 'max:160'],
            'fee_menu_rates' => [new FeeMenuRatesAboveFloor('merchant', null), new ExactlyOneFeeMenuFilled(), new FeeMenuRatesAboveReference('merchant', $agentRates, 'Based Fee Agent')],
            'payin_fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'cashback_agent_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'cashback_trx_toko_amount' => ['nullable', 'integer', 'min:0'],
            'cashback_trx_agent_amount' => ['nullable', 'integer', 'min:0'],
            'disbursement_fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'disbursement_fee_fixed' => ['nullable', 'integer', 'min:0'],
            'marketing_fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'marketing_fee_fixed' => ['nullable', 'integer', 'min:0'],
            'ma_fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'agent_fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'va_settlement_type' => ['nullable', 'string', 'max:80'],
            'is_whitelisted' => ['nullable', 'boolean'],
            'ip_dashboard' => ['nullable', 'string', 'max:255'],
            'api_ip_whitelist' => ['nullable', 'string', 'max:255'],
            'transaction_payment_gateway' => ['nullable', 'string', 'max:160'],
            'withdrawal_payment_gateway' => ['nullable', 'string', 'max:160'],
            'va_payment_gateway' => ['nullable', 'string', 'max:160'],
            'magnetopay_fee_1_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'magnetopay_fee_2_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'magnetopay_fee_3_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'magnetopay_fee_va_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'magnetopay_fee_withdrawal_fixed' => ['nullable', 'integer'],
            'magnetopay_fee_withdrawal_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);
        $activeMenu = array_key_first(array_filter($rates));

        $existingAdmin = $registration->merchant_id
            ? User::query()->where('merchant_id', $registration->merchant_id)->where('role', 'admin')->first()
            : null;
        $emailTaken = User::query()->where('email', $data['admin_email'])
            ->when($existingAdmin, fn ($query) => $query->where('id', '!=', $existingAdmin->id))
            ->exists();
        abort_if($emailTaken, 422, 'Email admin sudah dipakai user lain.');

        $payload = (array) ($registration->payload ?? []);
        // Magnetopay settlement/fee fields are MA-only (not collected on the
        // public onboarding form) - MA-entered values here are folded into
        // the stored payload too, so onboarding_payload stays the full record.
        foreach (['transaction_payment_gateway', 'withdrawal_payment_gateway', 'va_payment_gateway'] as $field) {
            if (filled($data[$field] ?? null)) {
                $payload[$field] = $data[$field];
            }
        }
        $merchantMdr = (float) $rates[$activeMenu];
        $payin = (float) ($data['payin_fee_percent'] ?? $payload['payin_fee_percent'] ?? 0);
        $cashbackAgent = (float) ($data['cashback_agent_percent'] ?? $payload['cashback_agent_percent'] ?? 0);
        $cashbackTrxToko = (int) ($data['cashback_trx_toko_amount'] ?? $payload['cashback_trx_toko_amount'] ?? 0);
        $cashbackTrxAgent = (int) ($data['cashback_trx_agent_amount'] ?? $payload['cashback_trx_agent_amount'] ?? 0);
        $settlementMethod = $feeMenus->settlementMethod($activeMenu);

        $toIpArray = fn (?string $value) => $value ? array_values(array_filter(array_map('trim', explode(',', $value)))) : null;

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
            ? $feeSync->snapshotFor($ownerAgent, $activeMenu, $merchantMdr)
            : ['merchant_mdr_percent' => $merchantMdr, 'agent_fee_percent' => 0.0, 'ma_fee_percent' => 0.0];
        // Fee MA %/Fee Agen % are entered directly by the MA on the approval
        // form (not auto-derived) - this is what lets a universal-link
        // registration (no agent_id yet) still get a real fee split instead
        // of silently defaulting to 0. The value stays on the merchant row
        // independently of agent_id, so mapping an agent later (Mapping
        // Agen) doesn't touch or require these.
        $feeSnapshot['ma_fee_percent'] = (float) ($data['ma_fee_percent'] ?? $payload['ma_fee_percent'] ?? $feeSnapshot['ma_fee_percent']);
        $feeSnapshot['agent_fee_percent'] = (float) ($data['agent_fee_percent'] ?? $payload['agent_fee_percent'] ?? $feeSnapshot['agent_fee_percent']);
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
            'va_callback_url' => $payload['va_callback_url'] ?? $merchant->va_callback_url,
            'callback_alpha_url' => $payload['callback_alpha_url'] ?? $merchant->callback_alpha_url,
            'ip_pg' => $toIpArray($payload['ip_pg'] ?? null) ?? $merchant->ip_pg,
            'ip_alpha' => $toIpArray($payload['ip_alpha'] ?? null) ?? $merchant->ip_alpha,
            'qris_settlement_type' => $payload['qris_settlement_type'] ?? $merchant->qris_settlement_type,
            'va_settlement_type' => $data['va_settlement_type'] ?? $payload['va_settlement_type'] ?? $merchant->va_settlement_type,
            'ip_whitelist_enabled' => filter_var($data['is_whitelisted'] ?? $payload['is_whitelisted'] ?? $merchant->ip_whitelist_enabled, FILTER_VALIDATE_BOOLEAN),
            'dashboard_ip_whitelist' => $toIpArray($data['ip_dashboard'] ?? $payload['ip_dashboard'] ?? null) ?? $merchant->dashboard_ip_whitelist,
            'api_ip_whitelist' => $toIpArray($data['api_ip_whitelist'] ?? $payload['api_ip_whitelist'] ?? null) ?? $merchant->api_ip_whitelist,
            'magnetopay_fee_1_percent' => (float) ($data['magnetopay_fee_1_percent'] ?? $payload['magnetopay_fee_1_percent'] ?? $merchant->magnetopay_fee_1_percent),
            'magnetopay_fee_2_percent' => (float) ($data['magnetopay_fee_2_percent'] ?? $payload['magnetopay_fee_2_percent'] ?? $merchant->magnetopay_fee_2_percent),
            'magnetopay_fee_3_percent' => (float) ($data['magnetopay_fee_3_percent'] ?? $payload['magnetopay_fee_3_percent'] ?? $merchant->magnetopay_fee_3_percent),
            'magnetopay_fee_va_percent' => (float) ($data['magnetopay_fee_va_percent'] ?? $payload['magnetopay_fee_va_percent'] ?? $merchant->magnetopay_fee_va_percent),
            'magnetopay_fee_withdrawal_fixed' => $data['magnetopay_fee_withdrawal_fixed'] ?? $payload['magnetopay_fee_withdrawal_fixed'] ?? $merchant->magnetopay_fee_withdrawal_fixed,
            'magnetopay_fee_withdrawal_percent' => (float) ($data['magnetopay_fee_withdrawal_percent'] ?? $payload['magnetopay_fee_withdrawal_percent'] ?? $merchant->magnetopay_fee_withdrawal_percent),
            'pic_email' => $payload['pic_email'] ?? $merchant->pic_email,
            'pic_telegram' => $payload['pic_telegram'] ?? $merchant->pic_telegram,
            'finance_email' => $payload['finance_email'] ?? $merchant->finance_email,
            'finance_telegram' => $payload['finance_telegram'] ?? $merchant->finance_telegram,
            'cs_email' => $payload['cs_email'] ?? $merchant->cs_email,
            'cs_telegram' => $payload['cs_telegram'] ?? $merchant->cs_telegram,
            'fee_menu' => $activeMenu,
            'fee_menu_rates' => $data['fee_menu_rates'],
            'settlement_method' => $settlementMethod,
            'payin_fee_percent' => $payin,
            'cashback_agent_percent' => $cashbackAgent,
            'cashback_trx_toko_amount' => $cashbackTrxToko,
            'cashback_trx_agent_amount' => $cashbackTrxAgent,
            ...$feeSnapshot,
            'disbursement_fee_fixed' => (int) ($data['disbursement_fee_fixed'] ?? $payload['disbursement_fee_fixed'] ?? $payload['withdrawal_fee'] ?? 0),
            'disbursement_fee_percent' => (float) ($data['disbursement_fee_percent'] ?? $payload['disbursement_fee_percent'] ?? 0),
            'marketing_fee_percent' => (float) ($data['marketing_fee_percent'] ?? $payload['marketing_fee_percent'] ?? 0),
            'marketing_fee_fixed' => (int) ($data['marketing_fee_fixed'] ?? $payload['marketing_fee_fixed'] ?? 0),
            'onboarding_payload' => $payload,
            'approved_at' => now(),
        ]);
        $merchant->save();

        $adminCredentialNote = '';
        if ($existingAdmin) {
            if ($existingAdmin->email !== $data['admin_email']) {
                $existingAdmin->forceFill(['email' => $data['admin_email']])->save();
            }
        } else {
            $demoPassword = config('paygrid.demo_password');
            $adminUser = User::query()->create([
                'name' => str($data['admin_email'])->before('@')->replace(['.', '_', '-'], ' ')->title()->toString(),
                'email' => $data['admin_email'],
                'role' => 'admin',
                'merchant_id' => $merchant->id,
                'password' => Hash::make($demoPassword),
                'plain_password' => $demoPassword,
            ]);
            $audit->record('merchant_registration.admin_created', $adminUser, null, $adminUser->only(['email', 'role', 'merchant_id']));
            $adminCredentialNote = ' Admin default: '.$adminUser->email.' / '.$demoPassword.'.';
        }

        $before = $registration->only(['status', 'merchant_id', 'approved_at']);
        $registration->update(['merchant_id' => $merchant->id, 'status' => 'approved', 'approved_at' => now()]);
        $audit->record('merchant_registration.approved', $registration, $before, $registration->only(array_keys($before)));
        $audit->record('merchant.created_from_registration', $merchant, null, $merchant->only(['agent_id', 'slug', 'name', 'gateway', 'merchant_type', 'approval_status']));
        if ($merchant->gateway === 'hilogate' && config('paygrid.gateway.hilogate.onboarding_email') && config('paygrid.gateway.hilogate.onboarding_password')) {
            ProvisionMerchantOnGateway::dispatch($merchant->id);
        }

        return back()->with('status', 'Merchant berhasil diapprove di PayGrid.'.$adminCredentialNote);
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

    public function downloadPdf(Request $request, MerchantRegistration $registration): \Illuminate\Http\Response
    {
        $this->authorizeMaRegistration($request, $registration);

        $pdf = Pdf::loadView('paygrid.merchant-registration-pdf', [
            'registration' => $registration,
            'detailRows' => $registration->detailRowsForMa(),
            'admin' => $registration->adminInfo(),
        ]);

        return $pdf->download('merchant-reg-'.Str::slug($registration->store_name).'-'.$registration->id.'.pdf');
    }

    public function exportCsv(Request $request, MerchantRegistration $registration): \Illuminate\Http\Response
    {
        $this->authorizeMaRegistration($request, $registration);
        abort_unless($registration->status === 'approved', 422, 'Cuma request yang sudah approved yang bisa di-export.');

        $merchant = $registration->merchant;
        $admin = $registration->adminInfo();
        $rows = [
            ['Nama Toko', $registration->store_name],
            ['Agen', $registration->agent?->name ?: '-'],
            ['Merchant ID', $merchant?->merchant_id ?: '-'],
            ['Merchant Key', $merchant?->merchant_key ?: '-'],
            ['Gateway', $merchant?->gateway ?: '-'],
            ['Merchant MDR (%)', $merchant ? number_format((float) $merchant->merchant_mdr_percent, 4) : '-'],
            ['Fee Agen (%)', $merchant ? number_format((float) $merchant->agent_fee_percent, 4) : '-'],
            ['Fee MA (%)', $merchant ? number_format((float) $merchant->ma_fee_percent, 4) : '-'],
            ['Admin Email', $admin['email']],
            ['Admin Password', $admin['password']],
            ['Transaction Callback', $merchant?->transaction_callback_url ?: '-'],
            ['Withdrawal Callback', $merchant?->withdrawal_callback_url ?: '-'],
        ];

        $filename = 'export-toko-'.Str::slug($registration->store_name).'-'.$registration->id.'.xls';

        return response($this->toSpreadsheetXml($rows), 200, [
            'Content-Type' => 'application/vnd.ms-excel',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * Excel 2003 SpreadsheetML XML - opens natively in Excel with no "format
     * mismatch" warning, unlike a CSV/HTML file simply renamed to .xls.
     */
    private function toSpreadsheetXml(array $rows): string
    {
        $cell = fn (string $value) => '<Cell><Data ss:Type="String">'.htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8').'</Data></Cell>';

        $xmlRows = '';
        foreach ($rows as [$label, $value]) {
            $xmlRows .= '<Row>'.$cell((string) $label).$cell((string) $value).'</Row>';
        }

        return <<<XML
        <?xml version="1.0"?>
        <?mso-application progid="Excel.Sheet"?>
        <Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
        <Worksheet ss:Name="Toko">
        <Table>{$xmlRows}</Table>
        </Worksheet>
        </Workbook>
        XML;
    }

    private function authorizeMaRegistration(Request $request, MerchantRegistration $registration): void
    {
        if ($request->user()?->role === 'superadmin') {
            return;
        }

        $registration->loadMissing('agent');
        $ownedByThisMa = $registration->agent_id === null || (int) $registration->agent?->ma_user_id === (int) $request->user()->id;
        abort_unless($request->user()?->role === 'ma' && $ownedByThisMa, 403);
    }
}
