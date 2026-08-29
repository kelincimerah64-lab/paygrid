<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

#[Fillable(['name', 'email', 'username', 'contact', 'role', 'is_active', 'merchant_id', 'base_hg_percent', 'connection_type', 'connection_fee_percent', 'settlement_method', 'settlement_fee_percent', 'ma_fee_percent', 'fee_menu', 'password', 'plain_password'])]
#[Hidden(['password', 'plain_password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * plain_password is encrypted at rest; a value encrypted under a since-rotated
     * APP_KEY can no longer be decrypted. Surface that as "unreadable" instead of
     * throwing, so one bad legacy row doesn't crash an account listing page.
     */
    public function readablePlainPassword(): ?string
    {
        try {
            return $this->plain_password;
        } catch (DecryptException) {
            return null;
        }
    }

    /**
     * Eloquent's dirty-checking for an `encrypted` cast decrypts the CURRENTLY
     * stored value to compare it against the new one, even when only setting
     * the field — so a legacy row with an undecryptable plain_password throws
     * on save() before the new value is ever written. Write both credential
     * columns directly to bypass that comparison, then refresh in-memory state.
     */
    public function resetCredentials(string $password): void
    {
        DB::table($this->getTable())->where($this->getKeyName(), $this->getKey())->update([
            'password' => Hash::make($password),
            'plain_password' => Crypt::encryptString($password),
            'updated_at' => now(),
        ]);
        $this->refresh();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'base_hg_percent' => 'decimal:4',
            'connection_fee_percent' => 'decimal:4',
            'settlement_fee_percent' => 'decimal:4',
            'ma_fee_percent' => 'decimal:4',
            'password' => 'hashed',
            'plain_password' => 'encrypted',
        ];
    }
}
