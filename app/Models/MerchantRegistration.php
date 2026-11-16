<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantRegistration extends Model
{
    protected $fillable = [
        'agent_id',
        'merchant_id',
        'token',
        'ip_address',
        'store_name',
        'engine_name',
        'merchant_type',
        'gateway',
        'settlement_method',
        'payload',
        'status',
        'revision_count',
        'submitted_to_ma_at',
        'approved_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'revision_count' => 'integer',
        'submitted_to_ma_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Magnetopay links are reusable (one link spawns many registrations over
     * time), so `agent_onboarding_links.merchant_registration_id` only ever
     * gets set for the old single-use flow - the actual originating link for
     * every submission (reusable or not) is stashed in the payload at
     * submit time instead (see MerchantRegistrationController::tokenStoreMagnetopay()).
     */
    public function onboardingLink(): ?AgentOnboardingLink
    {
        $linkId = ($this->payload ?? [])['agent_onboarding_link_id'] ?? null;

        return $linkId ? AgentOnboardingLink::query()->find($linkId) : null;
    }

    /**
     * detailRows()/detailRowsForMa() are flat label => string pairs (the PDF
     * just echoes $value directly), so a fixed-row group like "3 PIC rows" or
     * "5 bank accounts" from the payload needs collapsing into one readable
     * string here rather than being left as a nested array.
     */
    private function peopleRowsSummary(array $rows, array $fields): string
    {
        $summary = collect($rows)
            ->filter(fn ($row) => is_array($row) && collect($fields)->contains(fn ($f) => filled($row[$f] ?? null)))
            ->map(fn ($row) => collect($fields)->map(fn ($f) => $row[$f] ?? '')->filter()->implode(' / '))
            ->implode('; ');

        return $summary !== '' ? $summary : '-';
    }

    /**
     * Shared by the agent dashboard's Detail modal and the PDF export so both
     * always show the exact same fields - see simple-page.blade.php and
     * merchant-registration-pdf.blade.php.
     */
    public function detailRows(): array
    {
        $payload = (array) ($this->payload ?? []);
        $pct = fn ($value) => number_format((float) ($value ?? 0), 3, ',', '.').'%';
        $money = fn ($value) => number_format((int) ($value ?? 0), 0, ',', '.');

        return [
            'Form ID' => $this->onboardingLink()?->form_number ?? '-',
            'IP Pengisi Form' => $this->ip_address ?? '-',
            'Engine Name' => $this->engine_name ?: ($payload['engine_name'] ?? '-'),
            'Tipe Merchant' => strtoupper($this->merchant_type ?: ($payload['merchant_type'] ?? '-')),
            'Payment Gateway' => ucfirst($this->gateway ?: ($payload['gateway'] ?? '-')),
            'PIC Email' => $payload['pic_email'] ?? '-',
            'PIC Telegram' => $payload['pic_telegram'] ?? '-',
            'PIC untuk Kredensial' => $this->peopleRowsSummary((array) ($payload['pic_credentials'] ?? []), ['name', 'contact', 'email']),
            'Finance Email' => $payload['finance_email'] ?? '-',
            'Finance Telegram' => $payload['finance_telegram'] ?? '-',
            'Email Finance Role' => $payload['finance_role_email'] ?? '-',
            'CS Email' => $payload['cs_email'] ?? '-',
            'CS Telegram' => $payload['cs_telegram'] ?? '-',
            'IP Dashboard' => $payload['ip_dashboard'] ?? '-',
            'IP Finance' => $payload['ip_finance'] ?? '-',
            'Settlement Type ID' => $payload['settlement_type_id'] ?? $this->settlement_method ?? '-',
            'Transaction Callback' => $payload['transaction_callback_url'] ?? '-',
            'Withdrawal Callback' => $payload['withdrawal_callback_url'] ?? '-',
            'URL Web Panel Admin' => $payload['web_panel_admin_url'] ?? '-',
            'API IP Whitelist' => $payload['api_ip_whitelist'] ?? '-',
            'Callback Alpha' => $payload['callback_alpha_url'] ?? '-',
            'IP PG' => $payload['ip_pg'] ?? '-',
            'IP Alpha' => $payload['ip_alpha'] ?? '-',
            'Whitelist' => ($payload['is_whitelisted'] ?? false) ? 'Ya' : 'Tidak',
            'Transaction Gateway ID' => $payload['transaction_gateway_ids'] ?? '-',
            'Withdrawal Gateway ID' => $payload['withdrawal_gateway_ids'] ?? '-',
            'VA Callback' => $payload['va_callback_url'] ?? '-',
            'QRIS Settlement Type' => $payload['qris_settlement_type'] ?? '-',
            'VA Settlement Type' => $payload['va_settlement_type'] ?? '-',
            'Magnetopay Fee 1' => $pct($payload['magnetopay_fee_1_percent'] ?? 0),
            'Magnetopay Fee 2' => $pct($payload['magnetopay_fee_2_percent'] ?? 0),
            'Magnetopay Fee 3' => $pct($payload['magnetopay_fee_3_percent'] ?? 0),
            'Magnetopay Fee VA' => $pct($payload['magnetopay_fee_va_percent'] ?? 0),
            'Magnetopay Fee Withdrawal' => $payload['magnetopay_fee_withdrawal_fixed'] ?? '-',
            'Magnetopay Fee Withdrawal %' => $pct($payload['magnetopay_fee_withdrawal_percent'] ?? 0),
            'Merchant MDR' => $pct($payload['merchant_mdr_percent'] ?? 0),
            'Base MDR' => $pct($payload['base_mdr_percent'] ?? 0),
            'MA Fee' => $pct($payload['ma_fee_percent'] ?? 0),
            'Agent Fee' => $pct($payload['agent_fee_percent'] ?? 0),
            'Cash Back Toko' => $pct($payload['payin_fee_percent'] ?? 0),
            'Cash Back Agen' => $pct($payload['cashback_agent_percent'] ?? 0),
            'Cash Back Trx Toko' => $money($payload['cashback_trx_toko_amount'] ?? 0),
            'Cash Back Trx Agen' => $money($payload['cashback_trx_agent_amount'] ?? 0),
            'Disbursement Fee' => $payload['disbursement_fee_fixed'] ?? '-',
            'Whitelist Rekening Bank' => $this->peopleRowsSummary((array) ($payload['bank_account'] ?? []), ['name', 'number', 'bank']),
        ];
    }

    /**
     * MA's approval-list Detail view shows more than the agent's - HG
     * credentials, admin login, link topup - so this is intentionally a
     * separate field set, not a reuse of detailRows(). Shared by ma.blade.php
     * and the MA-side PDF export so both always match.
     */
    /**
     * Admin login shown on the approval card - derived from the submitted
     * payload with the same fallbacks/defaults used everywhere else this
     * shows up (ma.blade.php's row render and the MA-side PDF export).
     */
    public function adminInfo(): array
    {
        $payload = (array) ($this->payload ?? []);
        $email = $payload['admin_email'] ?? $payload['email_admin'] ?? $payload['email_pic'] ?? '-';

        return [
            'name' => $payload['admin_name'] ?? $payload['username'] ?? ($email !== '-' ? str($email)->before('@')->replace(['.', '_', '-'], ' ')->title()->toString() : '-'),
            'email' => $email,
            'password' => $payload['admin_password'] ?? $payload['password'] ?? config('paygrid.demo_password'),
        ];
    }

    public function detailRowsForMa(): array
    {
        $payload = (array) ($this->payload ?? []);
        $merchant = $this->merchant;
        $pct = fn ($value) => number_format((float) ($value ?? 0), 3, ',', '.').'%';
        $money = fn ($value) => 'Rp '.number_format((int) ($value ?? 0), 0, ',', '.');

        $payinFee = $merchant?->payin_fee_percent ?? ($payload['payin_fee_percent'] ?? $payload['engine_service_fee_percent'] ?? 0);
        $cashbackAgentFee = $merchant?->cashback_agent_percent ?? ($payload['cashback_agent_percent'] ?? 0);
        $cashbackTrxToko = $merchant?->cashback_trx_toko_amount ?? ($payload['cashback_trx_toko_amount'] ?? 0);
        $cashbackTrxAgent = $merchant?->cashback_trx_agent_amount ?? ($payload['cashback_trx_agent_amount'] ?? 0);

        return [
            'Form ID' => $this->onboardingLink()?->form_number ?? '-',
            'IP Pengisi Form' => $this->ip_address ?? '-',
            'Engine Name' => $this->engine_name ?: ($payload['engine_name'] ?? '-'),
            'Tipe Merchant' => strtoupper($this->merchant_type ?: ($payload['merchant_type'] ?? '-')),
            'PIC Email' => $payload['pic_email'] ?? '-',
            'PIC Telegram' => $payload['pic_telegram'] ?? '-',
            'PIC untuk Kredensial' => $this->peopleRowsSummary((array) ($payload['pic_credentials'] ?? []), ['name', 'contact', 'email']),
            'Finance Email' => $payload['finance_email'] ?? '-',
            'Finance Telegram' => $payload['finance_telegram'] ?? '-',
            'Email Finance Role' => $payload['finance_role_email'] ?? '-',
            'CS Email' => $payload['cs_email'] ?? '-',
            'CS Telegram' => $payload['cs_telegram'] ?? '-',
            'IP Dashboard' => $payload['ip_dashboard'] ?? '-',
            'IP Finance' => $payload['ip_finance'] ?? '-',
            'HG Merchant ID' => $merchant?->merchant_id ?? ($payload['merchant_id'] ?? $payload['hg_merchant_id'] ?? '-'),
            'HG Merchant Key' => $payload['merchant_key'] ?? $payload['hg_merchant_key'] ?? '-',
            'HG Group ID' => $this->agent?->hg_group_id ?? $payload['merchant_group_id'] ?? $payload['hg_group_id'] ?? '-',
            'Transaction Gateway ID' => $payload['transaction_gateway_ids'] ?? '-',
            'Withdrawal Gateway ID' => $payload['withdrawal_gateway_ids'] ?? '-',
            'Settlement Type ID' => $payload['settlement_type_id'] ?? $this->settlement_method ?? '-',
            'Transaction Callback' => $payload['transaction_callback_url'] ?? url('/api/callbacks/hilogate/transaction'),
            'Withdrawal Callback' => $payload['withdrawal_callback_url'] ?? url('/api/callbacks/hilogate/withdrawal'),
            'URL Web Panel Admin' => $payload['web_panel_admin_url'] ?? '-',
            'API IP Whitelist' => $payload['api_ip_whitelist'] ?? '15.232.137.74',
            'Callback Alpha' => $merchant?->callback_alpha_url ?? ($payload['callback_alpha_url'] ?? '-'),
            'IP PG' => $merchant?->ip_pg ? implode(', ', $merchant->ip_pg) : ($payload['ip_pg'] ?? '-'),
            'IP Alpha' => $merchant?->ip_alpha ? implode(', ', $merchant->ip_alpha) : ($payload['ip_alpha'] ?? '-'),
            'VA Callback' => $merchant?->va_callback_url ?? ($payload['va_callback_url'] ?? '-'),
            'QRIS Settlement Type' => $merchant?->qris_settlement_type ?? ($payload['qris_settlement_type'] ?? '-'),
            'VA Settlement Type' => $merchant?->va_settlement_type ?? ($payload['va_settlement_type'] ?? '-'),
            'Magnetopay Fee 1' => $pct($merchant?->magnetopay_fee_1_percent ?? ($payload['magnetopay_fee_1_percent'] ?? 0)),
            'Magnetopay Fee 2' => $pct($merchant?->magnetopay_fee_2_percent ?? ($payload['magnetopay_fee_2_percent'] ?? 0)),
            'Magnetopay Fee 3' => $pct($merchant?->magnetopay_fee_3_percent ?? ($payload['magnetopay_fee_3_percent'] ?? 0)),
            'Magnetopay Fee VA' => $pct($merchant?->magnetopay_fee_va_percent ?? ($payload['magnetopay_fee_va_percent'] ?? 0)),
            'Magnetopay Fee Withdrawal' => $merchant?->magnetopay_fee_withdrawal_fixed ?? ($payload['magnetopay_fee_withdrawal_fixed'] ?? '-'),
            'Magnetopay Fee Withdrawal %' => $pct($merchant?->magnetopay_fee_withdrawal_percent ?? ($payload['magnetopay_fee_withdrawal_percent'] ?? 0)),
            'Link Topup' => $payload['topup_url'] ?? $merchant?->topup_url ?? '-',
            'Whitelist' => ($payload['is_whitelisted'] ?? false) ? 'Ya' : 'Tidak',
            'Cash Back Toko' => $pct($payinFee),
            'Cash Back Agen' => $pct($cashbackAgentFee),
            'Cash Back Trx Toko' => $money($cashbackTrxToko),
            'Cash Back Trx Agen' => $money($cashbackTrxAgent),
            'Second TRX Fee' => $pct($payload['second_transaction_fee_percentage'] ?? 0),
            'Third TRX Fee' => $pct($payload['third_transaction_fee_percentage'] ?? 0),
            'Disbursement Fee' => $payload['disbursement_fee_fixed'] ?? $payload['withdrawal_fee'] ?? '-',
            'Disbursement Fee %' => $pct($payload['withdrawal_fee_percentage'] ?? 0),
            'Whitelist Rekening Bank' => $this->peopleRowsSummary((array) ($payload['bank_account'] ?? []), ['name', 'number', 'bank']),
        ];
    }

    /**
     * detailRowsForMa() above is a curated, Hilogate-shaped subset - fine for
     * the generic Request Approval modal, but it silently shows "-" for any
     * payload key it doesn't know about. The Magnetopay form's fields
     * (tokenStoreMagnetopay() just stores $request->except('_token') as-is)
     * aren't a fixed set, so this instead dumps every submitted field
     * dynamically - guaranteed to show exactly what was actually submitted,
     * not a hardcoded guess at it.
     */
    public function fullPayloadRowsForMa(): array
    {
        $rows = [
            'Nama Toko' => $this->store_name,
            'Engine Name' => $this->engine_name,
            'Tipe Merchant' => $this->merchant_type ? strtoupper($this->merchant_type) : null,
            'IP Pengisi Form' => $this->ip_address,
        ];

        foreach ((array) ($this->payload ?? []) as $key => $value) {
            $label = ucwords(str_replace('_', ' ', $key));

            // Repeating groups (pic_credentials, bank_account) are an array of
            // rows - e.g. 3 PIC slots or 5 bank account slots, most left blank.
            // Squishing each row into one string ("Budi / @tg / email; Sari / ...")
            // loses which value is which. Expand each non-empty row into its own
            // "Label N - Field" entries instead, so it reads like the form did.
            if (is_array($value) && $value !== [] && collect($value)->every(fn ($row) => is_array($row))) {
                $any = false;
                foreach (array_values($value) as $i => $row) {
                    foreach ((array) $row as $subKey => $subValue) {
                        if (! is_scalar($subValue) || $subValue === '') {
                            continue;
                        }
                        $subLabel = ucwords(str_replace('_', ' ', (string) $subKey));
                        $rows[$label.' '.($i + 1).' - '.$subLabel] = (string) $subValue;
                        $any = true;
                    }
                }
                if (! $any) {
                    $rows[$label] = '-';
                }
                continue;
            }

            $rows[$label] = $this->formatPayloadValue($value);
        }

        return $rows;
    }

    private function formatPayloadValue(mixed $value): string
    {
        if (is_scalar($value) || $value === null) {
            return $value !== null && $value !== '' ? (string) $value : '-';
        }

        if (! is_array($value)) {
            return '-';
        }

        $flat = implode(', ', array_filter(array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $value)));

        return $flat !== '' ? $flat : '-';
    }
}
