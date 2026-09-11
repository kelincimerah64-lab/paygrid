<?php

namespace App\Http\Controllers;

use App\Models\Merchant;
use App\Models\PaygridSetting;
use App\Models\SupportTicket;
use App\Models\TopupRequest;
use App\Services\SupportTicketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SupportTicketController extends Controller
{
    public function __construct(private readonly SupportTicketService $tickets)
    {
    }

    public function submit(Request $request, Merchant $merchant, SupportTicket $ticket): RedirectResponse
    {
        abort_unless($ticket->merchant_id === $merchant->id, 404);
        if ($ticket->submitted_to_center_at) {
            return back()->with('status', 'Tiket sudah dikirim ke CS pusat. Tunggu update status dari CS pusat.');
        }

        $data = $request->validate([
            'attachment' => ['nullable', 'image', 'max:4096'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $attachments = $ticket->attachments ?? [];
        if ($request->hasFile('attachment')) {
            $path = $request->file('attachment')->store('ticket-attachments/'.$merchant->id, 'local');
            $attachments[] = [
                'path' => $path,
                'disk' => 'local',
                'name' => $request->file('attachment')->getClientOriginalName(),
                'uploaded_at' => now()->toIso8601String(),
            ];
        }

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

        if (! $this->tickets->canCreateTicket($topupRequest)) {
            return back()->withErrors(['ticket' => 'Ticket pending baru bisa dibuat setelah pending '.PaygridSetting::value('ticket_pending_minutes', '40').' menit.']);
        }

        if ($topupRequest->ticket()->exists()) {
            return back()->with('status', 'Transaksi ini sudah menjadi tiket. Buka menu Tickets untuk submit ke CS pusat.');
        }

        $ticket = $this->tickets->createFromTopup($topupRequest);

        $ticket->update([
            'note' => $data['note'] ?? $ticket->note,
            'status' => 'not_started',
            'submitted_to_center_at' => null,
        ]);

        return back()->with('status', 'Transaksi berhasil jadi tiket. Buka menu Tickets untuk submit ke CS pusat.');
    }
}
