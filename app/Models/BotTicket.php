<?php

namespace App\Models;

use App\Services\BotTele\BotTime;
use Illuminate\Database\Eloquent\Model;

/**
 * Telegram support bot ticket (former "Tickets" sheet). Looked up by the
 * business key ticket_id, never by id. Timestamps are Asia/Jakarta wall-clock
 * strings set by the bot itself, so Eloquent timestamps are off.
 */
class BotTicket extends Model
{
    protected $connection = 'bottele';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'has_attachment' => 'boolean',
        'escalated' => 'boolean',
    ];

    public function getRouteKeyName(): string
    {
        return 'ticket_id';
    }

    public static function findByTicketId(string $ticketId): ?self
    {
        return static::query()->where('ticket_id', $ticketId)->first();
    }

    /** HGT-{Ymd Jakarta}-{epoch ms}{5 random digits}, same shape as n8n "Generate Ticket". */
    public static function newTicketId(): string
    {
        return 'HGT-'.BotTime::now()->format('Ymd').'-'.(int) floor(microtime(true) * 1000).str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);
    }
}
