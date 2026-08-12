<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GatewaySyncLog extends Model
{
    protected $fillable = [
        'merchant_id',
        'gateway',
        'direction',
        'endpoint',
        'http_status',
        'status',
        'message',
        'request_meta',
        'response_meta',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'request_meta' => 'array',
        'response_meta' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
