<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Technician check-in for the Telegram support bot (former "attendance" sheet), keyed by user_id. */
class BotAttendance extends Model
{
    protected $connection = 'bottele';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'expiry_notified' => 'boolean',
    ];
}
