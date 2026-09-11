<?php

namespace App\Services;

use App\Models\PaygridSetting;
use App\Models\SupportTicket;
use App\Models\TopupRequest;

class SupportTicketService
{
    public function canCreateTicket(TopupRequest $topupRequest): bool
    {
        if (in_array($topupRequest->status, ['expired', 'failed', 'rejected'], true)) {
            return true;
        }

        if ($topupRequest->status !== 'pending') {
            return false;
        }

        $deadline = $this->ticketDeadline($topupRequest);

        return $deadline !== null && now()->greaterThanOrEqualTo($deadline);
    }

    public function ticketDeadline(TopupRequest $topupRequest): ?\Carbon\CarbonInterface
    {
        $pendingMinutes = (int) PaygridSetting::value('ticket_pending_minutes', '40');

        if ($topupRequest->submitted_at) {
            $base = $topupRequest->submitted_at->lte(now()->addMinute()) ? $topupRequest->submitted_at : $topupRequest->created_at;

            return $base?->copy()->addMinutes($pendingMinutes);
        }

        if ($topupRequest->expires_at) {
            return $topupRequest->expires_at->copy()->addMinutes(max(0, $pendingMinutes - (int) config('paygrid.topup.expires_in_minutes', 30)));
        }

        return null;
    }

    public function createFromTopup(TopupRequest $topupRequest, ?string $note = null): SupportTicket
    {
        return SupportTicket::query()->firstOrCreate(
            ['topup_request_id' => $topupRequest->id],
            [
                'merchant_id' => $topupRequest->merchant_id,
                'ticket_no' => $this->ticketNo($topupRequest),
                'reference' => $topupRequest->gateway_ref_id,
                'client_reference' => $topupRequest->customer_reference ?: $topupRequest->transaction_id,
                'issue' => $topupRequest->status === 'pending' ? 'Payment pending' : 'Payment '.$topupRequest->status,
                'status' => 'not_started',
                'note' => $note ?? 'Ticket dibuat CS toko. Menunggu submit ke CS pusat.',
            ],
        );
    }

    public function ticketNo(TopupRequest $topupRequest): string
    {
        $suffix = preg_replace('/[^A-Za-z0-9]/', '', (string) ($topupRequest->gateway_ref_id ?: $topupRequest->id));

        return 'TCK-'.$topupRequest->id.'-'.substr($suffix ?: (string) $topupRequest->id, -8);
    }
}
