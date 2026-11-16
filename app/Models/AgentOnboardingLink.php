<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentOnboardingLink extends Model
{
    protected $fillable = [
        'agent_id',
        'kind',
        'merchant_registration_id',
        'token',
        'form_number',
        'created_by_user_id',
        'fee_menu',
        'fee_menu_rates',
        'disbursement_fee_percent',
        'disbursement_fee_fixed',
        'recipient_email',
        'recipient_telegram',
        'status',
        'reusable',
        'expires_at',
        'used_at',
    ];

    protected $casts = [
        'reusable' => 'boolean',
        'fee_menu_rates' => 'array',
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(MerchantRegistration::class, 'merchant_registration_id');
    }

    public function isUsable(): bool
    {
        return $this->status === 'active' && (! $this->expires_at || $this->expires_at->isFuture());
    }

    public function getRouteKeyName(): string
    {
        return 'token';
    }
}
