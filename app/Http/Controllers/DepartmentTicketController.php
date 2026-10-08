<?php

namespace App\Http\Controllers;

use App\Jobs\NotifyWaTicketLink;
use App\Models\MerchantTicket;
use App\Services\AuditLogService;
use App\Services\MerchantTicketService;
use App\Services\Navigation\MenuBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DepartmentTicketController extends Controller
{
    public function index(Request $request): View
    {
        $departments = $this->departmentsFor($request);
        $search = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', 'all');

        $tickets = MerchantTicket::query()
            ->with('merchant')
            ->whereIn('department', $departments)
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->when($search !== '', fn ($query) => $query->where(function ($nested) use ($search) {
                $nested->where('ticket_no', 'like', "{$search}%")
                    ->orWhereRelation('merchant', 'name', 'like', "{$search}%");
            }))
            ->latest('last_message_at')
            ->paginate(config('paygrid.reports.default_page_size', 50))
            ->withQueryString();

        return view('paygrid.dept-tickets', [
            'roleLabel' => 'CS Pusat',
            'menus' => app(MenuBuilder::class)->centerSupport(),
            'active' => 'manual-tickets',
            'tickets' => $tickets,
            'search' => $search,
            'status' => $status,
            'categories' => app(MerchantTicketService::class),
        ]);
    }

    public function show(Request $request, MerchantTicket $ticket): View
    {
        abort_unless(in_array($ticket->department, $this->departmentsFor($request), true), 403);

        return view('paygrid.dept-ticket-show', [
            'roleLabel' => 'CS Pusat',
            'menus' => app(MenuBuilder::class)->centerSupport(),
            'active' => 'manual-tickets',
            'ticket' => $ticket->load(['messages.user', 'merchant']),
            'categories' => app(MerchantTicketService::class),
        ]);
    }

    public function reply(Request $request, MerchantTicket $ticket, MerchantTicketService $tickets): RedirectResponse
    {
        abort_unless(in_array($ticket->department, $this->departmentsFor($request), true), 403);
        abort_if($ticket->status === 'closed', 422, 'Tiket sudah ditutup.');

        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        $tickets->addMessage($ticket, $request->user(), $data['body'], true);

        return back()->with('status', 'Balasan terkirim ke toko.');
    }

    public function updateStatus(Request $request, MerchantTicket $ticket, AuditLogService $audit): RedirectResponse
    {
        abort_unless(in_array($ticket->department, $this->departmentsFor($request), true), 403);

        $data = $request->validate(['status' => ['required', Rule::in(['open', 'in_progress', 'closed'])]]);
        $before = $ticket->only(['status', 'closed_at', 'closed_by_user_id']);
        $ticket->forceFill([
            'status' => $data['status'],
            'closed_at' => $data['status'] === 'closed' ? now() : null,
            'closed_by_user_id' => $data['status'] === 'closed' ? $request->user()->id : null,
        ])->save();
        $audit->record('merchant_ticket.status_updated', $ticket, $before, $ticket->only(['status', 'closed_at', 'closed_by_user_id']));

        // Keep the WA Tiket dashboard's live card in sync even when status is
        // changed from here instead - editActiveCard() no-ops if this ticket
        // never had a WA card (not every ticket does), so this is safe either way.
        NotifyWaTicketLink::dispatch($ticket->id, 'closed', '');

        return back()->with('status', 'Status tiket berhasil diubah.');
    }

    private function departmentsFor(Request $request): array
    {
        $department = $request->query('department');

        return in_array($department, MerchantTicketService::DEPARTMENTS, true) ? [$department] : MerchantTicketService::DEPARTMENTS;
    }
}
