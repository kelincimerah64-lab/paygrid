<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MerchantTicket;
use App\Models\MerchantTicketMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class N8nTicketApprovalController extends Controller
{
    public function update(Request $request, MerchantTicket $ticket): JsonResponse
    {
        $token = (string) config('services.n8n.callback_token');
        $provided = (string) $request->bearerToken();
        abort_unless($token !== '' && hash_equals($token, $provided), 401);

        abort_unless($ticket->approval_status === 'waiting', 422, 'Tiket ini tidak sedang menunggu approval.');

        $data = $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected'])],
            'note' => ['nullable', 'string', 'max:2000'],
            'approved_by' => ['nullable', 'string', 'max:255'],
        ]);

        $isRejected = $data['status'] === 'rejected';

        $ticket->forceFill([
            'approval_status' => $data['status'],
            'approval_by' => $data['approved_by'] ?? null,
            'approval_note' => $isRejected ? ($data['note'] ?? null) : null,
            'approval_completed_at' => now(),
            'status' => $isRejected ? 'closed' : $ticket->status,
            'closed_at' => $isRejected ? now() : $ticket->closed_at,
        ])->save();

        $summary = $isRejected
            ? 'Permintaan IP Whitelist ditolak via WhatsApp'.($data['approved_by'] ?? null ? ' oleh '.$data['approved_by'] : '').'. Alasan: '.($data['note'] ?? '-')
            : 'Permintaan IP Whitelist disetujui via WhatsApp'.($data['approved_by'] ?? null ? ' oleh '.$data['approved_by'] : '').'.';

        MerchantTicketMessage::query()->create([
            'merchant_ticket_id' => $ticket->id,
            'user_id' => null,
            'is_staff' => true,
            'body' => $summary,
        ]);

        return response()->json(['ok' => true]);
    }
}
