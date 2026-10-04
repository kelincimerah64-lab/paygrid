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
    /**
     * Approver's landing page after login - they have no merchant-scoped
     * dashboard, just the queue of tickets waiting on them, company-wide.
     */
    public function pendingApprovals(): View
    {
        $tickets = MerchantTicket::query()
            ->with(['merchant', 'createdBy'])
            ->where('approval_status', 'waiting')
            ->latest('created_at')
            ->paginate(config('paygrid.reports.default_page_size', 50));

        return view('paygrid.ma-wa-tickets-pending', [
            'roleLabel' => 'Approver',
            'menus' => [],
            'active' => 'wa-tickets',
            'tickets' => $tickets,
        ]);
    }

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
            'categoryMeta' => MerchantTicketService::CATEGORY_META,
        ]);
    }

    public function store(Request $request, MerchantTicketService $tickets, AuditLogService $audit): RedirectResponse
    {
        $merchantIds = $this->scopedMerchantIds($request->user());
        $department = (string) $request->input('department');
        $category = (string) $request->input('category');

        $data = $request->validate(array_merge([
            'merchant_id' => ['required', Rule::in($merchantIds)],
            'department' => ['required', Rule::in(MerchantTicketService::DEPARTMENTS)],
            'category' => ['required', 'string'],
            'description' => ['required', 'string', 'max:500'],
        ], $tickets->fieldRules($category)));
        abort_unless(array_key_exists($category, $tickets->categoriesFor($department)), 422, 'Kategori tidak valid untuk department ini.');

        $needsApproval = $tickets->needsPilotApproval($department, $category);
        $merchant = Merchant::query()->findOrFail($data['merchant_id']);
        $ticket = $tickets->create($merchant, $request->user(), [
            'department' => $department,
            'category' => $category,
            'description' => $data['description'],
            'metadata' => $tickets->metadataFrom($category, $data),
        ]);
        if ($needsApproval) {
            $ticket->forceFill(['approval_status' => 'waiting'])->save();
        }
        $audit->record('wa_ticket.created', $ticket, null, $ticket->only(['merchant_id', 'department', 'category', 'ticket_no', 'approval_status']));

        $groupId = $needsApproval ? config('paygrid.whatsapp.approval_group_id') : config('paygrid.whatsapp.handling_group_id');
        NotifyWaTicketLink::dispatch($ticket->id, 'created', (string) $groupId);

        $status = $needsApproval ? 'Tiket dibuat: '.$ticket->ticket_no.'. Menunggu approval, notifikasi dikirim ke grup approval.' : 'Tiket dibuat: '.$ticket->ticket_no.'. Notifikasi WA dikirim.';

        return redirect()->route('wa-tickets.show', $ticket)->with('status', $status);
    }

    public function show(Request $request, MerchantTicket $ticket, MenuBuilder $menus): View
    {
        $user = $request->user();
        $this->authorizeTicket($user, $ticket);
        [$roleLabel, $menus, $active] = match ($user->role) {
            'approver' => ['Approver', [], 'wa-tickets'],
            'cs_pusat' => ['CS Pusat', $menus->centerSupport(), 'manual-tickets'],
            default => ['MA', $menus->ma(), 'wa-tickets'],
        };

        return view('paygrid.ma-wa-tickets-show', [
            'roleLabel' => $roleLabel,
            'menus' => $menus,
            'active' => $active,
            'canApprove' => in_array($user->role, ['approver', 'superadmin'], true),
            'isCreator' => $ticket->created_by_user_id === $user->id,
            'ticket' => $ticket->load(['merchant', 'claimedBy', 'messages.user']),
            'teammates' => $this->teammates(),
        ]);
    }

    public function approve(Request $request, MerchantTicket $ticket, AuditLogService $audit): RedirectResponse
    {
        $this->authorizeTicket($request->user(), $ticket);
        abort_unless($ticket->approval_status === 'waiting', 422, 'Tiket ini tidak sedang menunggu approval.');
        abort_if($ticket->created_by_user_id === $request->user()->id, 403, 'Tidak bisa approve tiket buatan sendiri.');

        $before = $ticket->only(['approval_status', 'approval_by']);
        $ticket->forceFill([
            'approval_status' => 'approved',
            'approval_by' => $request->user()->name,
            'approval_completed_at' => now(),
        ])->save();
        $audit->record('wa_ticket.approved', $ticket, $before, $ticket->only(['approval_status', 'approval_by']));

        NotifyWaTicketLink::dispatch($ticket->id, 'approved', (string) config('paygrid.whatsapp.handling_group_id'));

        return back()->with('status', 'Tiket disetujui, notifikasi dikirim ke grup handling.');
    }

    public function reject(Request $request, MerchantTicket $ticket, AuditLogService $audit): RedirectResponse
    {
        $this->authorizeTicket($request->user(), $ticket);
        abort_unless($ticket->approval_status === 'waiting', 422, 'Tiket ini tidak sedang menunggu approval.');

        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);
        $before = $ticket->only(['approval_status', 'approval_by', 'status']);
        $ticket->forceFill([
            'approval_status' => 'rejected',
            'approval_by' => $request->user()->name,
            'approval_note' => $data['note'] ?? null,
            'approval_completed_at' => now(),
            'status' => 'closed',
            'closed_at' => now(),
        ])->save();
        $audit->record('wa_ticket.rejected', $ticket, $before, $ticket->only(['approval_status', 'approval_by', 'status']));

        return redirect()->route('wa-tickets.show', $ticket)->with('status', 'Tiket ditolak dan ditutup.');
    }

    public function claim(Request $request, MerchantTicket $ticket, MerchantTicketService $tickets): RedirectResponse
    {
        $this->authorizeTicket($request->user(), $ticket);
        abort_if($ticket->status === 'closed', 422, 'Tiket sudah ditutup.');
        abort_if($ticket->approval_status === 'waiting', 422, 'Tiket ini masih menunggu approval.');

        $won = $tickets->claim($ticket, $request->user());

        return back()->with('status', $won ? 'Tiket berhasil diambil.' : 'Tiket ini sudah diambil orang lain duluan.');
    }

    public function reply(Request $request, MerchantTicket $ticket, MerchantTicketService $tickets): RedirectResponse
    {
        $this->authorizeTicket($request->user(), $ticket);
        abort_if($ticket->status === 'closed', 422, 'Tiket sudah ditutup.');
        abort_if($ticket->approval_status === 'waiting', 422, 'Tiket ini masih menunggu approval.');

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
        abort_if($ticket->approval_status === 'waiting', 422, 'Tiket ini masih menunggu approval.');

        $data = $request->validate(['to_user_id' => ['nullable', 'integer', 'exists:users,id']]);
        $to = $data['to_user_id'] ? User::query()->find($data['to_user_id']) : null;

        $tickets->transfer($ticket, $request->user(), $to);
        NotifyWaTicketLink::dispatch($ticket->id, 'transferred', (string) config('paygrid.whatsapp.handling_group_id'));

        return back()->with('status', $to ? 'Tiket dialihkan ke '.$to->name.'.' : 'Tiket dilepas ke antrean.');
    }

    public function close(Request $request, MerchantTicket $ticket, MerchantTicketService $tickets): RedirectResponse
    {
        $this->authorizeTicket($request->user(), $ticket);
        abort_if($ticket->status === 'closed', 422, 'Tiket sudah ditutup.');

        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);
        $tickets->closeWithNote($ticket, $request->user(), $data['note'] ?? null);

        return redirect()->route('wa-tickets.show', $ticket)->with('status', 'Tiket ditutup.');
    }

    /**
     * MA sees only their own agents' merchants (the create/index dashboard is
     * MA-only anyway). CS Pusat and Approver handle tickets company-wide - they
     * only ever reach a specific ticket via its WA link, never browse a
     * merchant-scoped list, so there's nothing to narrow their access by.
     */
    private function scopedMerchantIds(User $user): \Illuminate\Support\Collection
    {
        if (in_array($user->role, ['superadmin', 'cs_pusat', 'approver'], true)) {
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
