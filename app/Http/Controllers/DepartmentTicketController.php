<?php

namespace App\Http\Controllers;

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
            'roleLabel' => $this->labelFor($departments),
            'menus' => app(MenuBuilder::class)->deptTickets(),
            'active' => 'tickets',
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
            'roleLabel' => $this->labelFor($this->departmentsFor($request)),
            'menus' => app(MenuBuilder::class)->deptTickets(),
            'active' => 'tickets',
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
        $before = $ticket->only(['status', 'closed_at']);
        $ticket->forceFill([
            'status' => $data['status'],
            'closed_at' => $data['status'] === 'closed' ? now() : null,
        ])->save();
        $audit->record('merchant_ticket.status_updated', $ticket, $before, $ticket->only(['status', 'closed_at']));

        return back()->with('status', 'Status tiket berhasil diubah.');
    }

    private function departmentsFor(Request $request): array
    {
        $role = $request->user()?->role;
        if ($role === 'cs_support') {
            return ['cs'];
        }
        if ($role === 'tech_support') {
            return ['tech'];
        }
        $department = $request->query('department');

        return in_array($department, MerchantTicketService::DEPARTMENTS, true) ? [$department] : MerchantTicketService::DEPARTMENTS;
    }

    private function labelFor(array $departments): string
    {
        if (count($departments) > 1) {
            return 'CS & Tech Support';
        }

        return $departments[0] === 'tech' ? 'Tech Support' : 'CS Support';
    }
}
