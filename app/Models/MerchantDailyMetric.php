<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantDailyMetric extends Model
{
    protected $fillable = [
        'merchant_id',
        'agent_id',
        'metric_date',
        'gateway',
        'data_source',
        'trx_total',
        'trx_success',
        'trx_success_processed',
        'trx_success_unprocessed',
        'trx_pending',
        'trx_expired',
        'amount_success',
        'amount_success_processed',
        'amount_success_unprocessed',
        'amount_total',
        'amount_pending',
        'amount_expired',
        'net_success',
        'fee_total',
        'settled_total',
        'ticket_total',
    ];

    protected $casts = [
        'metric_date' => 'date',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
