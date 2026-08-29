<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Agent extends Model
{
    protected $fillable = [
        'ma_user_id',
        'code',
        'name',
        'email',
        'password_plain',
        'contact',
        'hg_group_id',
        'base_hg_percent',
        'connection_type',
        'engine_type',
        'connection_fee_percent',
        'settlement_method',
        'settlement_fee_percent',
        'ma_fee_percent',
        'default_agent_fee_percent',
        'fee_menu',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'base_hg_percent' => 'decimal:4',
        'connection_fee_percent' => 'decimal:4',
        'settlement_fee_percent' => 'decimal:4',
        'ma_fee_percent' => 'decimal:4',
        'default_agent_fee_percent' => 'decimal:4',
    ];

    public function ma(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ma_user_id');
    }

    public function merchants(): HasMany
    {
        return $this->hasMany(Merchant::class);
    }

    public function onboardingLinks(): HasMany
    {
        return $this->hasMany(AgentOnboardingLink::class);
    }
}
