<?php

namespace App\Jobs;

use App\Models\MerchantTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NotifyIpWhitelistApproval implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 30;

    public function __construct(public readonly int $ticketId) {}

    public function handle(): void
    {
        $webhookUrl = config('services.n8n.ip_whitelist_webhook_url');
        if (! $webhookUrl) {
            return;
        }

        $ticket = MerchantTicket::query()->with(['merchant.agent', 'createdBy'])->findOrFail($this->ticketId);
        $merchant = $ticket->merchant;

        $response = Http::timeout(15)->post($webhookUrl, [
            'ticket_no' => $ticket->ticket_no,
            'ticket_id' => $ticket->id,
            'merchant_group' => $merchant->agent?->name ?? '-',
            'merchant_name' => $merchant->name,
            'reported_by' => $ticket->createdBy?->name ?? '-',
            'description' => $ticket->description,
            'callback_url' => url('/api/n8n/tickets/'.$ticket->id.'/approval'),
        ]);

        if ($response->failed()) {
            Log::warning('NotifyIpWhitelistApproval webhook call failed', [
                'ticket_id' => $ticket->id,
                'status' => $response->status(),
            ]);
            $response->throw();
        }
    }
}
