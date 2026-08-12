<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentOnboardingLink extends Model
{
    protected $fillable = [
        'agent_id',
        'merchant_registration_id',
        'token',
        'recipient_email',
        'recipient_telegram',
        'status',
        'expires_at',
        'used_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
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
