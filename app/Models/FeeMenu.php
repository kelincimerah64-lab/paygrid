<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeeMenu extends Model
{
    protected $fillable = [
        'key',
        'label',
        'sort_order',
        'ma_enabled',
        'ma_floor',
        'agent_enabled',
        'agent_floor',
        'merchant_enabled',
        'merchant_floor',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'ma_enabled' => 'boolean',
        'ma_floor' => 'float',
        'agent_enabled' => 'boolean',
        'agent_floor' => 'float',
        'merchant_enabled' => 'boolean',
        'merchant_floor' => 'float',
    ];
}
