<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
