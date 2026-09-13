<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\MerchantTicket;
use App\Models\MerchantTicketMessage;
use App\Models\User;
use Illuminate\Http\UploadedFile;

class MerchantTicketService
{
    public const DEPARTMENTS = ['cs', 'tech'];

    public const CS_CATEGORIES = [
        'settlement' => 'Settlement',
        'transaction' => 'Cek Transaksi',
        'topup' => 'Topup',
        'others' => 'Others',
    ];

    public const TECH_CATEGORIES = [
        'merchant_details' => 'Merchant Details/User Role',
        'ip_whitelist' => 'IP Whitelist/VPS',
        'technical_issue' => 'Technical Issue',
        'others' => 'Others',
    ];

    public function categoriesFor(string $department): array
    {
        return $department === 'tech' ? self::TECH_CATEGORIES : self::CS_CATEGORIES;
    }

    public function create(Merchant $merchant, User $user, array $data, array $attachments = []): MerchantTicket
    {
        $ticket = MerchantTicket::query()->create([
            'merchant_id' => $merchant->id,
            'created_by_user_id' => $user->id,
            'ticket_no' => 'PENDING',
            'department' => $data['department'],
            'category' => $data['category'],
            'description' => $data['description'],
            'attachments' => $this->storeAttachments($merchant, $attachments),
            'last_message_at' => now(),
        ]);

        $ticket->forceFill(['ticket_no' => 'TK-'.str_pad((string) $ticket->id, 5, '0', STR_PAD_LEFT)])->save();

        return $ticket;
    }

    private function storeAttachments(Merchant $merchant, array $attachments): array
    {
        return collect($attachments)
            ->filter()
            ->map(fn (UploadedFile $file) => [
                'disk' => 'local',
                'path' => $file->store('ticket-attachments/'.$merchant->id, 'local'),
                'name' => $file->getClientOriginalName(),
            ])
            ->values()
            ->all();
    }

    public function addMessage(MerchantTicket $ticket, User $user, string $body, bool $isStaff): MerchantTicketMessage
    {
        $message = $ticket->messages()->create([
            'user_id' => $user->id,
            'is_staff' => $isStaff,
            'body' => $body,
        ]);

        $ticket->forceFill(['last_message_at' => now()]);
        if ($isStaff && $ticket->status === 'open') {
            $ticket->status = 'in_progress';
        }
        $ticket->save();

        return $message;
    }
}
