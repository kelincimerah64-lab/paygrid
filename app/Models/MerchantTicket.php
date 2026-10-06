<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MerchantTicket extends Model
{
    protected $fillable = [
        'merchant_id',
        'created_by_user_id',
        'claimed_by_user_id',
        'claimed_at',
        'ticket_no',
        'department',
        'category',
        'title',
        'description',
        'metadata',
        'attachments',
        'status',
        'approval_status',
        'approval_by',
        'approval_note',
        'approval_completed_at',
        'last_message_at',
        'closed_at',
        'closed_by_user_id',
        'wa_active_message_id',
        'wa_active_chat_id',
        'wa_reminder_stage',
        'wa_reminder_stage_at',
    ];

    protected $casts = [
        'attachments' => 'array',
        'metadata' => 'array',
        'last_message_at' => 'datetime',
        'closed_at' => 'datetime',
        'approval_completed_at' => 'datetime',
        'claimed_at' => 'datetime',
        'wa_reminder_stage_at' => 'datetime',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function claimedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by_user_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(MerchantTicketMessage::class)->orderBy('created_at');
    }
}
