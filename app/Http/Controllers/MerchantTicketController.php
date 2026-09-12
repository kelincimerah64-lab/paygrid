<?php

namespace App\Http\Controllers;

use App\Models\Merchant;
use App\Models\MerchantTicket;
use App\Services\AuditLogService;
use App\Services\MerchantTicketService;
use App\Services\Navigation\MenuBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MerchantTicketController extends Controller
{
    public function index(Request $request, Merchant $merchant, MerchantTicketService $tickets, MenuBuilder $menus): View
    {
        abort_unless($merchant->general_ticket_enabled, 404);

        return view('paygrid.merchant-tickets', [
            'roleLabel' => $merchant->name.' - Tickets',
            'merchant' => $merchant,
            'menus' => $this->menusFor($request, $merchant, $menus),
            'active' => $this->activeFor($request),
            'csCategories' => MerchantTicketService::CS_CATEGORIES,
            'techCategories' => MerchantTicketService::TECH_CATEGORIES,
            'tickets' => MerchantTicket::query()
                ->where('merchant_id', $merchant->id)
                ->latest('last_message_at')
                ->paginate(config('paygrid.reports.default_page_size', 50))
                ->withQueryString(),
        ]);
    }

    public function store(Request $request, Merchant $merchant, MerchantTicketService $tickets, AuditLogService $audit): RedirectResponse
    {
        abort_unless($merchant->general_ticket_enabled, 404);

        $department = $request->input('department');
        $categoryKeys = array_keys($tickets->categoriesFor((string) $department));

        $data = $request->validate([
            'department' => ['required', Rule::in(MerchantTicketService::DEPARTMENTS)],
            'category' => ['required', Rule::in($categoryKeys)],
            'description' => ['required', 'string', 'max:2000'],
            'attachment' => ['nullable', 'image', 'max:4096'],
        ]);

        $ticket = $tickets->create($merchant, $request->user(), $data, $request->file('attachment'));
        $audit->record('merchant_ticket.created', $ticket, null, $ticket->only(['merchant_id', 'department', 'category', 'ticket_no']));

        return redirect()->route('merchant.tickets.show', [$merchant, $ticket])->with('status', 'Tiket berhasil dibuat: '.$ticket->ticket_no);
    }

    public function show(Request $request, Merchant $merchant, MerchantTicket $ticket, MenuBuilder $menus): View
    {
        abort_unless($merchant->general_ticket_enabled, 404);
        abort_unless((int) $ticket->merchant_id === (int) $merchant->id, 404);

        return view('paygrid.merchant-ticket-show', [
            'roleLabel' => $merchant->name.' - Tickets',
            'merchant' => $merchant,
            'menus' => $this->menusFor($request, $merchant, $menus),
            'active' => $this->activeFor($request),
            'ticket' => $ticket->load('messages.user'),
        ]);
    }

    private function menusFor(Request $request, Merchant $merchant, MenuBuilder $menus): array
    {
        return match ($request->user()?->role) {
            'ma' => $menus->ma(),
            'agent' => $menus->agent(),
            'cs', 'readonly_cs' => $menus->merchantCs($merchant),
            default => $menus->merchantAdmin($merchant),
        };
    }

    private function activeFor(Request $request): string
    {
        return in_array($request->user()?->role, ['ma', 'agent'], true) ? 'create-ticket' : 'support-ticket';
    }

    public function reply(Request $request, Merchant $merchant, MerchantTicket $ticket, MerchantTicketService $tickets): RedirectResponse
    {
        abort_unless($merchant->general_ticket_enabled, 404);
        abort_unless((int) $ticket->merchant_id === (int) $merchant->id, 404);
        abort_if($ticket->status === 'closed', 422, 'Tiket sudah ditutup.');

        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        $tickets->addMessage($ticket, $request->user(), $data['body'], false);

        return back()->with('status', 'Balasan terkirim.');
    }

    public function attachment(Merchant $merchant, MerchantTicket $ticket): StreamedResponse
    {
        abort_unless($merchant->general_ticket_enabled, 404);
        abort_unless((int) $ticket->merchant_id === (int) $merchant->id, 404);
        abort_unless($ticket->attachment_path, 404);
        abort_unless(Storage::disk($ticket->attachment_disk)->exists($ticket->attachment_path), 404);

        return Storage::disk($ticket->attachment_disk)->download($ticket->attachment_path, $ticket->attachment_name ?: basename($ticket->attachment_path));
    }
}
