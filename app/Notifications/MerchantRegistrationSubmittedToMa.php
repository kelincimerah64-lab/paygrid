<?php

namespace App\Notifications;

use App\Models\MerchantRegistration;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class MerchantRegistrationSubmittedToMa extends Notification
{
    use Queueable;

    public function __construct(private MerchantRegistration $registration)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Request toko baru',
            'message' => ($this->registration->agent?->name ?: 'Agen').' mengirim request '.$this->registration->store_name.' ke MA.',
            'registration_id' => $this->registration->id,
            'agent_id' => $this->registration->agent_id,
            'store_name' => $this->registration->store_name,
            'url' => route('ma.approvals'),
        ];
    }
}
