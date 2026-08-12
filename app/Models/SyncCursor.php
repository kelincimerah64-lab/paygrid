<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncCursor extends Model
{
    protected $fillable = [
        'merchant_id',
        'gateway',
        'cursor_type',
        'last_synced_at',
        'last_gateway_ref_id',
        'last_payload_at',
        'meta',
    ];

    protected $casts = [
        'last_synced_at' => 'datetime',
        'last_payload_at' => 'datetime',
        'meta' => 'array',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
