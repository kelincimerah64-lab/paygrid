<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['telegram_bot_user_id', 'absen_date', 'absen_at', 'is_verified'])]
class TelegramAbsence extends Model
{
    protected $casts = [
        'absen_date' => 'date',
        'absen_at' => 'datetime',
        'is_verified' => 'boolean',
    ];

    public function telegramBotUser(): BelongsTo
    {
        return $this->belongsTo(TelegramBotUser::class);
    }
}
