<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramBroadcastMessage extends Model
{
    protected $fillable = [
        'batch_id', 'sent_by', 'message', 'group_name', 'chat_id',
        'telegram_message_id', 'status', 'deleted_from_telegram_at', 'deleted_by',
    ];

    protected $casts = [
        'deleted_from_telegram_at' => 'datetime',
    ];

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
