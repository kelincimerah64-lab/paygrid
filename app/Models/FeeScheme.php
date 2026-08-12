<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeeScheme extends Model
{
    protected $fillable = ['owner_type', 'owner_id', 'merchant_mdr_percent', 'base_mdr_percent', 'payin_fee_percent', 'settlement_fee_percent', 'ma_fee_percent', 'agent_fee_percent', 'toko_fee_percent', 'effective_from', 'effective_to', 'created_by_user_id'];

    protected $casts = [
        'merchant_mdr_percent' => 'decimal:4', 'base_mdr_percent' => 'decimal:4',
        'payin_fee_percent' => 'decimal:4', 'settlement_fee_percent' => 'decimal:4',
        'ma_fee_percent' => 'decimal:4', 'agent_fee_percent' => 'decimal:4',
        'toko_fee_percent' => 'decimal:4', 'effective_from' => 'datetime', 'effective_to' => 'datetime',
    ];
}
