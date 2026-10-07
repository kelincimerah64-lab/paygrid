<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['telegram_user_id', 'username', 'first_name', 'dm_chat_id', 'group_chat_id', 'status', 'is_cs', 'user_id', 'pin_encrypted', 'pin_expires_at', 'pin_generated_by', 'activated_at', 'joined_group_at', 'left_group_at'])]
#[Hidden(['pin_encrypted'])]
class TelegramBotUser extends Model
{
    protected function casts(): array
    {
        return [
            'is_cs' => 'boolean',
            'pin_encrypted' => 'encrypted',
            'pin_expires_at' => 'datetime',
            'activated_at' => 'datetime',
            'joined_group_at' => 'datetime',
            'left_group_at' => 'datetime',
        ];
    }

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

    public function generatePin(?int $generatedByUserId = null): string
    {
        $pin = (string) random_int(100000, 999999);

        $this->forceFill([
            'pin_encrypted' => $pin,
            'pin_expires_at' => now()->addMinutes(30),
            'pin_generated_by' => $generatedByUserId,
        ])->save();

        return $pin;
    }

    public function pinIsActive(): bool
    {
        return $this->pin_encrypted !== null && $this->pin_expires_at?->isFuture();
    }

    /**
     * pin_encrypted is encrypted at rest; a value encrypted under a since-rotated
     * APP_KEY can no longer be decrypted. Surface that as unreadable instead of
     * throwing, so one bad legacy row doesn't crash the monitor dashboard.
     */
    public function readablePin(): ?string
    {
        try {
            return $this->pin_encrypted;
        } catch (DecryptException) {
            return null;
        }
    }
}
