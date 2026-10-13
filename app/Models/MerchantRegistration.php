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
            'Engine Name' => $this->engine_name ?: ($payload['engine_name'] ?? '-'),
            'Tipe Merchant' => strtoupper($this->merchant_type ?: ($payload['merchant_type'] ?? '-')),
            'Payment Gateway' => ucfirst($this->gateway ?: ($payload['gateway'] ?? '-')),
            'PIC Email' => $payload['pic_email'] ?? '-',
            'PIC Telegram' => $payload['pic_telegram'] ?? '-',
            'Finance Email' => $payload['finance_email'] ?? '-',
            'Finance Telegram' => $payload['finance_telegram'] ?? '-',
            'CS Email' => $payload['cs_email'] ?? '-',
            'CS Telegram' => $payload['cs_telegram'] ?? '-',
            'IP Dashboard' => $payload['ip_dashboard'] ?? '-',
            'IP Finance' => $payload['ip_finance'] ?? '-',
            'Settlement Type ID' => $payload['settlement_type_id'] ?? $this->settlement_method ?? '-',
            'Transaction Callback' => $payload['transaction_callback_url'] ?? '-',
            'Withdrawal Callback' => $payload['withdrawal_callback_url'] ?? '-',
            'API IP Whitelist' => $payload['api_ip_whitelist'] ?? '-',
            'Whitelist' => ($payload['is_whitelisted'] ?? false) ? 'Ya' : 'Tidak',
            'Transaction Gateway ID' => $payload['transaction_gateway_ids'] ?? '-',
            'Withdrawal Gateway ID' => $payload['withdrawal_gateway_ids'] ?? '-',
            'Merchant MDR' => $pct($payload['merchant_mdr_percent'] ?? 0),
            'Base MDR' => $pct($payload['base_mdr_percent'] ?? 0),
            'MA Fee' => $pct($payload['ma_fee_percent'] ?? 0),
            'Agent Fee' => $pct($payload['agent_fee_percent'] ?? 0),
            'Cash Back Toko' => $pct($payload['payin_fee_percent'] ?? 0),
            'Cash Back Agen' => $pct($payload['cashback_agent_percent'] ?? 0),
            'Cash Back Trx Toko' => $money($payload['cashback_trx_toko_amount'] ?? 0),
            'Cash Back Trx Agen' => $money($payload['cashback_trx_agent_amount'] ?? 0),
            'Disbursement Fee' => $payload['disbursement_fee_fixed'] ?? '-',
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
            'Engine Name' => $this->engine_name ?: ($payload['engine_name'] ?? '-'),
            'Tipe Merchant' => strtoupper($this->merchant_type ?: ($payload['merchant_type'] ?? '-')),
            'PIC Email' => $payload['pic_email'] ?? '-',
            'PIC Telegram' => $payload['pic_telegram'] ?? '-',
            'Finance Email' => $payload['finance_email'] ?? '-',
            'Finance Telegram' => $payload['finance_telegram'] ?? '-',
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
            'API IP Whitelist' => $payload['api_ip_whitelist'] ?? '15.232.137.74',
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
        ];
    }
}
