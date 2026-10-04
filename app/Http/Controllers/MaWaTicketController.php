<?php

namespace App\Http\Controllers;

use App\Jobs\NotifyWaTicketLink;
use App\Models\Merchant;
use App\Models\MerchantTicket;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\MerchantTicketService;
use App\Services\Navigation\MenuBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Pilot/sandbox menu for the "link-first" WhatsApp ticketing plan - lives
 * entirely under its own routes and view so the existing ticket wizard
 * (MerchantTicketController/MaTicketController) and CS Pusat flow
 * (DepartmentTicketController) are untouched. Tickets created here are real
 * rows in merchant_tickets, so they're also visible in the normal CS/Tech/
 * Finance queues - this just adds claim + WhatsApp-link notification on top.
 */
class MaWaTicketController extends Controller
{
    public function index(Request $request, MenuBuilder $menus): View
    {
        $merchantIds = $this->scopedMerchantIds($request->user());

        $tickets = MerchantTicket::query()
            ->with(['merchant', 'claimedBy'])
            ->whereIn('merchant_id', $merchantIds)
            ->latest('created_at')
            ->paginate(config('paygrid.reports.default_page_size', 50));

        return view('paygrid.ma-wa-tickets-index', [
            'roleLabel' => 'MA',
            'menus' => $menus->ma(),
            'active' => 'wa-tickets',
            'tickets' => $tickets,
        ]);
    }

    public function create(Request $request, MenuBuilder $menus): View
    {
        $merchants = Merchant::query()
            ->whereIn('id', $this->scopedMerchantIds($request->user()))
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('paygrid.ma-wa-tickets-create', [
            'roleLabel' => 'MA',
            'menus' => $menus->ma(),
            'active' => 'wa-tickets',
            'merchants' => $merchants,
            'departments' => MerchantTicketService::DEPARTMENTS,
            'categoriesByDepartment' => [
                'cs' => MerchantTicketService::CS_CATEGORIES,
                'tech' => MerchantTicketService::TECH_CATEGORIES,
                'finance' => MerchantTicketService::FINANCE_CATEGORIES,
            ],
            'departmentLabels' => MerchantTicketService::DEPARTMENT_LABELS,
        ]);
    }

    public function store(Request $request, MerchantTicketService $tickets, AuditLogService $audit): RedirectResponse
    {
        $merchantIds = $this->scopedMerchantIds($request->user());

        $data = $request->validate([
            'merchant_id' => ['required', Rule::in($merchantIds)],
            'department' => ['required', Rule::in(MerchantTicketService::DEPARTMENTS)],
            'category' => ['required', 'string'],
            'description' => ['required', 'string', 'max:500'],
        ]);
        abort_unless(array_key_exists($data['category'], $tickets->categoriesFor($data['department'])), 422, 'Kategori tidak valid untuk department ini.');

        $merchant = Merchant::query()->findOrFail($data['merchant_id']);
        $ticket = $tickets->create($merchant, $request->user(), [
            'department' => $data['department'],
            'category' => $data['category'],
            'description' => $data['description'],
        ]);
        $audit->record('wa_ticket.created', $ticket, null, $ticket->only(['merchant_id', 'department', 'category', 'ticket_no']));

        NotifyWaTicketLink::dispatch($ticket->id, 'created');

        return redirect()->route('ma.wa-tickets.show', $ticket)->with('status', 'Tiket dibuat: '.$ticket->ticket_no.'. Notifikasi WA dikirim.');
    }

    public function show(Request $request, MerchantTicket $ticket, MenuBuilder $menus): View
    {
        $this->authorizeTicket($request->user(), $ticket);

        return view('paygrid.ma-wa-tickets-show', [
            'roleLabel' => 'MA',
            'menus' => $menus->ma(),
            'active' => 'wa-tickets',
            'ticket' => $ticket->load(['merchant', 'claimedBy', 'messages.user']),
            'teammates' => $this->teammates(),
        ]);
    }

    public function claim(Request $request, MerchantTicket $ticket, MerchantTicketService $tickets): RedirectResponse
    {
        $this->authorizeTicket($request->user(), $ticket);
        abort_if($ticket->status === 'closed', 422, 'Tiket sudah ditutup.');

        $won = $tickets->claim($ticket, $request->user());

        return back()->with('status', $won ? 'Tiket berhasil diambil.' : 'Tiket ini sudah diambil orang lain duluan.');
    }

    public function reply(Request $request, MerchantTicket $ticket, MerchantTicketService $tickets): RedirectResponse
    {
        $this->authorizeTicket($request->user(), $ticket);
        abort_if($ticket->status === 'closed', 422, 'Tiket sudah ditutup.');

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'is_internal' => ['nullable', 'boolean'],
        ]);
        $tickets->addMessage($ticket, $request->user(), $data['body'], true, (bool) ($data['is_internal'] ?? false));

        return back()->with('status', 'Pesan terkirim.');
    }

    public function transfer(Request $request, MerchantTicket $ticket, MerchantTicketService $tickets): RedirectResponse
    {
        $this->authorizeTicket($request->user(), $ticket);
        abort_if($ticket->status === 'closed', 422, 'Tiket sudah ditutup.');

        $data = $request->validate(['to_user_id' => ['nullable', 'integer', 'exists:users,id']]);
        $to = $data['to_user_id'] ? User::query()->find($data['to_user_id']) : null;

        $tickets->transfer($ticket, $request->user(), $to);
        NotifyWaTicketLink::dispatch($ticket->id, 'transferred');

        return back()->with('status', $to ? 'Tiket dialihkan ke '.$to->name.'.' : 'Tiket dilepas ke antrean.');
    }

    public function close(Request $request, MerchantTicket $ticket, MerchantTicketService $tickets): RedirectResponse
    {
        $this->authorizeTicket($request->user(), $ticket);
        abort_if($ticket->status === 'closed', 422, 'Tiket sudah ditutup.');

        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);
        $tickets->closeWithNote($ticket, $request->user(), $data['note'] ?? null);

        return redirect()->route('ma.wa-tickets.show', $ticket)->with('status', 'Tiket ditutup.');
    }

    private function scopedMerchantIds(User $user): \Illuminate\Support\Collection
    {
        if ($user->role === 'superadmin') {
            return Merchant::query()->pluck('id');
        }

        return Merchant::query()->whereRelation('agent', 'ma_user_id', $user->id)->pluck('id');
    }

    private function authorizeTicket(User $user, MerchantTicket $ticket): void
    {
        abort_unless($this->scopedMerchantIds($user)->contains($ticket->merchant_id), 403);
    }

    private function teammates(): \Illuminate\Support\Collection
    {
        return User::query()
            ->whereIn('role', ['cs_pusat', 'ma', 'superadmin'])
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'role']);
    }
}
