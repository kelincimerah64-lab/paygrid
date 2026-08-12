<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportTicket extends Model
{
    protected $fillable = [
        'merchant_id',
        'topup_request_id',
        'ticket_no',
        'reference',
        'client_reference',
        'issue',
        'status',
        'center_status',
        'note',
        'center_note',
        'center_updated_by_user_id',
        'center_updated_at',
        'attachments',
        'submitted_to_center_at',
        'closed_at',
    ];

    protected $casts = [
        'attachments' => 'array',
        'center_updated_at' => 'datetime',
        'submitted_to_center_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function topupRequest(): BelongsTo
    {
        return $this->belongsTo(TopupRequest::class);
    }

    public function centerUpdatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'center_updated_by_user_id');
    }
}
