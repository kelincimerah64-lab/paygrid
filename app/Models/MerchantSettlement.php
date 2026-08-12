<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantSettlement extends Model
{
    protected $fillable = [
        'merchant_id',
        'gateway',
        'gateway_merchant_id',
        'reference',
        'settlement_type',
        'settlement_date',
        'status',
        'batch_name',
        'batch_from',
        'batch_until',
        'trx_count',
        'total_amount',
        'total_fee',
        'net_amount',
        'merchant_name',
        'merchant_group_name',
        'processed_at',
        'gateway_created_at',
        'gateway_updated_at',
        'payload',
        'synced_at',
    ];

    protected $casts = [
        'settlement_date' => 'date',
        'processed_at' => 'datetime',
        'gateway_created_at' => 'datetime',
        'gateway_updated_at' => 'datetime',
        'payload' => 'array',
        'synced_at' => 'datetime',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
