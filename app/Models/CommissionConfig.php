<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommissionConfig extends Model
{
    protected $fillable = [
        'ma_user_id',
        'name',
        'agent_id',
        'status',
        'apply_scope',
    ];

    public function maUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ma_user_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function days(): HasMany
    {
        return $this->hasMany(CommissionConfigDay::class);
    }

    public function merchants(): BelongsToMany
    {
        return $this->belongsToMany(Merchant::class, 'commission_config_merchants');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(MerchantCommissionReport::class, 'commission_config_id');
    }
}
