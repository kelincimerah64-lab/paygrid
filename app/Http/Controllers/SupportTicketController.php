<?php

namespace App\Http\Controllers;

use App\Models\Merchant;
use App\Models\PaygridSetting;
use App\Models\SupportTicket;
use App\Models\TopupRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SupportTicketController extends Controller
{
    public function submit(Request $request, Merchant $merchant, SupportTicket $ticket): RedirectResponse
    {
        abort_unless($ticket->merchant_id === $merchant->id, 404);
        if ($ticket->submitted_to_center_at) {
            return back()->with('status', 'Tiket sudah dikirim ke CS pusat. Tunggu update status dari CS pusat.');
        }

        $data = $request->validate([
            'attachment' => ['required', 'image', 'max:4096'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $path = $request->file('attachment')->store('ticket-attachments/'.$merchant->id, 'public');
        $attachments = $ticket->attachments ?? [];
        $attachments[] = [
            'path' => $path,
            'name' => $request->file('attachment')->getClientOriginalName(),
            'uploaded_at' => now()->toIso8601String(),
        ];

        $ticket->update([
            'attachments' => $attachments,
            'note' => $data['note'] ?? $ticket->note,
            'status' => 'open',
            'center_status' => 'not_started',
            'submitted_to_center_at' => now(),
        ]);

        return back()->with('status', 'Tiket berhasil dikirim ke CS pusat.');
    }

    public function createFromTopup(Request $request, Merchant $merchant, TopupRequest $topupRequest): RedirectResponse
    {
        abort_unless($topupRequest->merchant_id === $merchant->id, 404);

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        if (! $this->canCreateTicket($topupRequest)) {
            return back()->withErrors(['ticket' => 'Ticket pending baru bisa dibuat setelah pending '.PaygridSetting::value('ticket_pending_minutes', '40').' menit.']);
        }

        if ($topupRequest->ticket()->exists()) {
            return back()->with('status', 'Transaksi ini sudah menjadi tiket. Buka menu Tickets untuk submit ke CS pusat.');
        }

        $ticket = SupportTicket::query()->firstOrCreate(
            ['topup_request_id' => $topupRequest->id],
            [
                'merchant_id' => $merchant->id,
                'ticket_no' => $this->ticketNo($topupRequest),
                'reference' => $topupRequest->gateway_ref_id,
                'client_reference' => $topupRequest->customer_reference ?: $topupRequest->transaction_id,
                'issue' => $topupRequest->status === 'pending' ? 'Payment pending' : 'Payment '.$topupRequest->status,
                'status' => 'not_started',
                'note' => 'Ticket dibuat CS toko. Menunggu submit ke CS pusat.',
            ],
        );

        $ticket->update([
            'note' => $data['note'] ?? $ticket->note,
            'status' => 'not_started',
            'submitted_to_center_at' => null,
        ]);

        return back()->with('status', 'Transaksi berhasil jadi tiket. Buka menu Tickets untuk submit ke CS pusat.');
    }

    private function canCreateTicket(TopupRequest $request): bool
    {
        if (in_array($request->status, ['expired', 'failed', 'rejected'], true)) {
            return true;
        }

        if ($request->status !== 'pending') {
            return false;
        }

        $deadline = $this->ticketDeadline($request);

        return $deadline !== null && now()->greaterThanOrEqualTo($deadline);
    }

    private function ticketDeadline(TopupRequest $request): ?\Carbon\CarbonInterface
    {
        $pendingMinutes = (int) PaygridSetting::value('ticket_pending_minutes', '40');

        if ($request->submitted_at) {
            return $request->submitted_at->copy()->addMinutes($pendingMinutes);
        }

        if ($request->expires_at) {
            return $request->expires_at->copy()->addMinutes(max(0, $pendingMinutes - (int) config('paygrid.topup.expires_in_minutes', 30)));
        }

        return null;
    }

    private function ticketNo(TopupRequest $request): string
    {
        $suffix = preg_replace('/[^A-Za-z0-9]/', '', (string) ($request->gateway_ref_id ?: $request->id));

        return 'TCK-'.$request->id.'-'.substr($suffix ?: (string) $request->id, -8);
    }
}
