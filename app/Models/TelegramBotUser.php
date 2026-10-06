<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['telegram_user_id', 'username', 'first_name', 'dm_chat_id', 'group_chat_id', 'status', 'user_id', 'pin_hash', 'pin_expires_at', 'pin_generated_by', 'activated_at', 'joined_group_at', 'left_group_at'])]
#[Hidden(['pin_hash'])]
class TelegramBotUser extends Model
{
    protected $casts = [
        'pin_expires_at' => 'datetime',
        'activated_at' => 'datetime',
        'joined_group_at' => 'datetime',
        'left_group_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pinGeneratedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pin_generated_by');
    }

    public function absences(): HasMany
    {
        return $this->hasMany(TelegramAbsence::class);
    }

    public function isActivated(): bool
    {
        return $this->status === 'activated';
    }

    /**
     * Still a member of the group but never completed /activate + PIN - the
     * signal an intruder in the CS group looks like from the dashboard side.
     */
    public function isSuspect(): bool
    {
        return ! $this->isActivated() && $this->left_group_at === null;
    }

    public function displayName(): string
    {
        return $this->user?->name ?: $this->first_name ?: ($this->username ? '@'.$this->username : 'Telegram #'.$this->telegram_user_id);
    }
}
