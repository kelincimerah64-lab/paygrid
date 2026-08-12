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
        'submitted_to_ma_at',
        'approved_at',
    ];

    protected $casts = [
        'payload' => 'array',
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
}
