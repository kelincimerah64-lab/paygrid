<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionConfigDay extends Model
{
    protected $fillable = [
        'commission_config_id',
        'day_of_week',
        'enabled',
        'rate_percent',
        'wd_fee_fixed',
        'notes',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'rate_percent' => 'decimal:4',
    ];

    public function config(): BelongsTo
    {
        return $this->belongsTo(CommissionConfig::class, 'commission_config_id');
    }
}
