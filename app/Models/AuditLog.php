<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'actor_user_id', 'actor_role', 'action', 'target_type', 'target_id',
        'before_payload', 'after_payload', 'ip_address', 'user_agent', 'created_at',
    ];

    protected $casts = [
        'before_payload' => 'array',
        'after_payload' => 'array',
        'created_at' => 'datetime',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
