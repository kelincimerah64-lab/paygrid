<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeeSnapshot extends Model
{
    protected $fillable = ['topup_request_id', 'merchant_id', 'merchant_mdr_percent', 'base_mdr_percent', 'payin_fee_percent', 'settlement_fee_percent', 'ma_fee_percent', 'agent_fee_percent', 'toko_fee_percent'];

    protected $casts = [
        'merchant_mdr_percent' => 'decimal:4', 'base_mdr_percent' => 'decimal:4',
        'payin_fee_percent' => 'decimal:4', 'settlement_fee_percent' => 'decimal:4',
        'ma_fee_percent' => 'decimal:4', 'agent_fee_percent' => 'decimal:4', 'toko_fee_percent' => 'decimal:4',
    ];

    public function topupRequest(): BelongsTo
    {
        return $this->belongsTo(TopupRequest::class);
    }
}
