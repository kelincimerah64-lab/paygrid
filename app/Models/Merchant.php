<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Merchant extends Model
{
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
        'pic_email',
        'pic_telegram',
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
        'toko_fee_percent',
        'payin_fee_percent',
        'fee_menu',
        'fee_menu_rates',
        'disbursement_fee_fixed',
        'onboarding_payload',
        'approved_at',
    ];

    protected $casts = [
        'merchant_key' => 'encrypted',
        'topup_enabled' => 'boolean',
        'minimum_topup_amount' => 'integer',
        'merchant_mdr_percent' => 'decimal:4',
        'base_mdr_percent' => 'decimal:4',
        'connection_fee_percent' => 'decimal:4',
        'settlement_fee_percent' => 'decimal:4',
        'ma_fee_percent' => 'decimal:4',
        'agent_fee_percent' => 'decimal:4',
        'toko_fee_percent' => 'decimal:4',
        'payin_fee_percent' => 'decimal:4',
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
