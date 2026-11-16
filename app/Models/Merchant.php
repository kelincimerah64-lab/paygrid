<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Merchant extends Model
{
    protected $attributes = [
        'general_ticket_enabled' => true,
    ];

    protected $fillable = [
        'agent_id',
        'slug',
        'name',
        'merchant_id',
        'merchant_key',
        'merchant_group_name',
        'merchant_group_id',
        'merchant_type',
        'engine_type',
        'engine_base_url',
        'engine_token_api',
        'engine_agent_back_panel',
        'gateway',
        'approval_status',
        'provisioning_status',
        'provisioning_error',
        'provisioning_attempts',
        'provisioned_at',
        'topup_enabled',
        'topup_url',
        'minimum_topup_amount',
        'transaction_callback_url',
        'withdrawal_callback_url',
        'va_callback_url',
        'callback_alpha_url',
        'qris_settlement_type',
        'va_settlement_type',
        'ip_whitelist_enabled',
        'dashboard_ip_whitelist',
        'api_ip_whitelist',
        'ip_pg',
        'ip_alpha',
        'magnetopay_fee_1_percent',
        'magnetopay_fee_2_percent',
        'magnetopay_fee_3_percent',
        'magnetopay_fee_va_percent',
        'magnetopay_fee_withdrawal_fixed',
        'magnetopay_fee_withdrawal_percent',
        'pic_email',
        'pic_telegram',
        'pic_whatsapp',
        'finance_email',
        'finance_telegram',
        'cs_email',
        'cs_telegram',
        'merchant_mdr_percent',
        'base_mdr_percent',
        'connection_fee_percent',
        'settlement_method',
        'settlement_fee_percent',
        'ma_fee_percent',
        'agent_fee_percent',
        'fees_per_store',
        'toko_fee_percent',
        'payin_fee_percent',
        'cashback_agent_percent',
        'cashback_trx_toko_amount',
        'cashback_trx_agent_amount',
        'fee_menu',
        'fee_menu_rates',
        'disbursement_fee_fixed',
        'disbursement_fee_percent',
        'marketing_fee_percent',
        'marketing_fee_fixed',
        'onboarding_payload',
        'approved_at',
    ];

    protected $casts = [
        'merchant_key' => 'encrypted',
        'engine_token_api' => 'encrypted',
        'ip_whitelist_enabled' => 'boolean',
        'dashboard_ip_whitelist' => 'array',
        'api_ip_whitelist' => 'array',
        'ip_pg' => 'array',
        'ip_alpha' => 'array',
        'magnetopay_fee_1_percent' => 'decimal:4',
        'magnetopay_fee_2_percent' => 'decimal:4',
        'magnetopay_fee_3_percent' => 'decimal:4',
        'magnetopay_fee_va_percent' => 'decimal:4',
        'magnetopay_fee_withdrawal_fixed' => 'integer',
        'magnetopay_fee_withdrawal_percent' => 'decimal:4',
        'topup_enabled' => 'boolean',
        'general_ticket_enabled' => 'boolean',
        'minimum_topup_amount' => 'integer',
        'merchant_mdr_percent' => 'decimal:4',
        'base_mdr_percent' => 'decimal:4',
        'connection_fee_percent' => 'decimal:4',
        'settlement_fee_percent' => 'decimal:4',
        'ma_fee_percent' => 'decimal:4',
        'agent_fee_percent' => 'decimal:4',
        'fees_per_store' => 'boolean',
        'toko_fee_percent' => 'decimal:4',
        'payin_fee_percent' => 'decimal:4',
        'cashback_agent_percent' => 'decimal:4',
        'cashback_trx_toko_amount' => 'integer',
        'cashback_trx_agent_amount' => 'integer',
        'disbursement_fee_fixed' => 'integer',
        'disbursement_fee_percent' => 'decimal:4',
        'marketing_fee_percent' => 'decimal:4',
        'marketing_fee_fixed' => 'integer',
        'fee_menu_rates' => 'array',
        'onboarding_payload' => 'array',
        'approved_at' => 'datetime',
        'provisioned_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function topupRequests(): HasMany
    {
        return $this->hasMany(TopupRequest::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class);
    }

    public function merchantTickets(): HasMany
    {
        return $this->hasMany(MerchantTicket::class);
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(MerchantDailyMetric::class);
    }

    public function isCm(): bool
    {
        return $this->merchant_type === 'cm';
    }

    public function isScript(): bool
    {
        return $this->merchant_type === 'script';
    }

    public function minimumTopupAmount(): int
    {
        return (int) ($this->minimum_topup_amount ?: config('paygrid.topup.minimum_amount'));
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
