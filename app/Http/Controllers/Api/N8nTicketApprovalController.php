<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MerchantTicket;
use App\Services\MerchantTicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * @deprecated Called by the n8n "PayGrid - IP Whitelist WA Approval" workflow, which is inactive
 * and replaced by App\Services\BotTele\IpWhitelistBridge. Kept until it is confirmed nothing
 * else calls it; the logic now lives in MerchantTicketService::applyExternalApproval().
 */
class N8nTicketApprovalController extends Controller
{
    public function update(Request $request, string $ticketNo, MerchantTicketService $tickets): JsonResponse
    {
        $token = (string) config('services.n8n.callback_token');
        $provided = (string) $request->bearerToken();
        abort_unless($token !== '' && hash_equals($token, $provided), 401);

        $ticket = MerchantTicket::query()->where('ticket_no', $ticketNo)->firstOrFail();
        abort_unless($ticket->approval_status === 'waiting', 422, 'Tiket ini tidak sedang menunggu approval.');

        $data = $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected'])],
            'note' => ['nullable', 'string', 'max:2000'],
            'approved_by' => ['nullable', 'string', 'max:255'],
        ]);

        $tickets->applyExternalApproval($ticket, $data['status'], $data['approved_by'] ?? null, $data['note'] ?? null);

        return response()->json(['ok' => true]);
    }
}
