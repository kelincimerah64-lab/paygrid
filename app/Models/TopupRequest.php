<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TopupRequest extends Model
{
    protected $fillable = [
        'merchant_id',
        'customer_reference',
        'idempotency_key',
        'gateway',
        'data_source',
        'payment_id',
        'gateway_ref_id',
        'qr_string',
        'payment_url',
        'rrn',
        'transaction_id',
        'status',
        'amount',
        'net_amount',
        'fee_amount',
        'is_processed',
        'processed_by_user_id',
        'checked_by_email',
        'checked_by_role',
        'processed_at',
        'submitted_at',
        'callback_received_at',
        'last_synced_at',
        'expires_at',
        'gateway_payload',
    ];

    protected $casts = [
        'is_processed' => 'boolean',
        'processed_at' => 'datetime',
        'submitted_at' => 'datetime',
        'callback_received_at' => 'datetime',
        'expires_at' => 'datetime',
        'gateway_payload' => 'array',
        'last_synced_at' => 'datetime',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function ticket(): HasOne
    {
        return $this->hasOne(SupportTicket::class);
    }

    public function feeSnapshot(): HasOne
    {
        return $this->hasOne(FeeSnapshot::class);
    }
}
