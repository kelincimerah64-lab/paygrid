<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TelegramTicketReminder extends Notification
{
    use Queueable;

    public function __construct(private array $ticket)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Reminder ticket bot Telegram',
            'message' => ($this->ticket['requester_name'] ?: 'Requester').' menunggu tindak lanjut untuk ticket '.$this->ticket['ticket_id'].'.',
            'ticket_id' => $this->ticket['ticket_id'],
            'requester_name' => $this->ticket['requester_name'] ?? null,
            'category' => $this->ticket['category'] ?? null,
            'created_at' => $this->ticket['created_at']?->toIso8601String(),
            'url' => route('center-support.bot-monitoring', ['bot_q' => $this->ticket['ticket_id']]),
        ];
    }
}
