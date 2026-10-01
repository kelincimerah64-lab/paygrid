<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantWithdrawal extends Model
{
    protected $fillable = [
        'merchant_id',
        'gateway',
        'gateway_withdrawal_id',
        'ref_id',
        'status',
        'amount',
        'net_amount',
        'fee',
        'bank_code',
        'bank_name',
        'account_number',
        'account_name',
        'gateway_created_at',
        'gateway_completed_at',
        'gateway_payload',
        'synced_at',
    ];

    protected $casts = [
        'gateway_payload' => 'array',
        'gateway_created_at' => 'datetime',
        'gateway_completed_at' => 'datetime',
        'synced_at' => 'datetime',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
