<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class TopupRequest extends Model
{
    protected $fillable = [
        'merchant_id',
        'customer_reference',
        'idempotency_key',
        'public_token',
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
        'succeeded_at',
        'callback_received_at',
        'last_synced_at',
        'expires_at',
        'gateway_payload',
        'cs_note',
    ];

    protected $casts = [
        'is_processed' => 'boolean',
        'processed_at' => 'datetime',
        'submitted_at' => 'datetime',
        'succeeded_at' => 'datetime',
        'callback_received_at' => 'datetime',
        'expires_at' => 'datetime',
        'gateway_payload' => 'array',
        'last_synced_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (TopupRequest $request): void {
            $request->public_token ??= (string) Str::uuid();
        });
    }

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

    public function successDurationLabel(): string
    {
        if (! $this->submitted_at || ! $this->succeeded_at) {
            return '-';
        }

        $seconds = max(0, $this->submitted_at->diffInSeconds($this->succeeded_at, false));
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainingSeconds = $seconds % 60;

        if ($hours > 0) {
            return $hours.'h '.$minutes.'m '.$remainingSeconds.'s';
        }

        if ($minutes > 0) {
            return $minutes.'m '.$remainingSeconds.'s';
        }

        return $remainingSeconds.'s';
    }

}
