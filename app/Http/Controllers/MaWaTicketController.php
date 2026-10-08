<?php

namespace App\Http\Controllers;

use App\Jobs\NotifyWaTicketLink;
use App\Models\Merchant;
use App\Models\MerchantTicket;
use App\Models\MerchantTicketMessage;
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
        $ticket->forceFill(['wa_reminder_stage_at' => now()])->save();
        $audit->record('wa_ticket.created', $ticket, null, $ticket->only(['merchant_id', 'department', 'category', 'ticket_no', 'approval_status']));

        // create() already notified the approval group when needed - only the
        // non-approval case still needs a push here, straight to the handling
        // group.
        if (! $needsApproval) {
            NotifyWaTicketLink::dispatch($ticket->id, 'created', (string) config('paygrid.whatsapp.handling_group_id'));
        }

        $status = $needsApproval ? 'Tiket dibuat: '.$ticket->ticket_no.'. Menunggu approval, notifikasi dikirim ke grup approval.' : 'Tiket dibuat: '.$ticket->ticket_no.'. Notifikasi WA dikirim.';

        return redirect()->route('wa-tickets.show', $ticket)->with('status', $status);
    }

    public function show(Request $request, MerchantTicket $ticket, MenuBuilder $menus, MerchantTicketService $tickets): View
    {
        $user = $request->user();
        $this->authorizeTicket($user, $ticket);
        [$roleLabel, $menus, $active] = match ($user->role) {
            'approver' => ['Approver', [], 'wa-tickets'],
            'cs_pusat' => ['CS Pusat', $menus->centerSupport(), 'manual-tickets'],
            default => ['MA', $menus->ma(), 'wa-tickets'],
        };

        $tickets->markViewed($ticket, $user);

        return view('paygrid.ma-wa-tickets-show', [
            'roleLabel' => $roleLabel,
            'menus' => $menus,
            'active' => $active,
            'canApprove' => in_array($user->role, ['approver', 'superadmin'], true),
            'isCreator' => $ticket->created_by_user_id === $user->id,
            'ticket' => $ticket->load(['merchant', 'claimedBy', 'messages.user']),
            'views' => $ticket->views()->with('user')->get()->keyBy('user_id'),
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

        NotifyWaTicketLink::dispatch($ticket->id, 'rejected', '');

        return redirect()->route('wa-tickets.show', $ticket)->with('status', 'Tiket ditolak dan ditutup.');
    }

    public function claim(Request $request, MerchantTicket $ticket, MerchantTicketService $tickets): RedirectResponse
    {
        $this->authorizeTicket($request->user(), $ticket);
        abort_if($ticket->status === 'closed', 422, 'Tiket sudah ditutup.');
        abort_if($ticket->approval_status === 'waiting', 422, 'Tiket ini masih menunggu approval.');

        $won = $tickets->claim($ticket, $request->user());
        if ($won) {
            NotifyWaTicketLink::dispatch($ticket->id, 'claimed', '');
        }

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
        $isInternal = (bool) ($data['is_internal'] ?? false);
        // Internal discussion stays open to any staff with access, even if
        // someone else holds the claim - it's the coordination channel (e.g.
        // "can you take this?"). The toko-facing side is the one that
        // actually needs exclusivity, so only that branch checks ownership.
        if (! $isInternal) {
            $this->abortIfClaimedByAnother($request->user(), $ticket);
        }
        abort_if(! $isInternal && ! $ticket->claimed_by_user_id, 422, 'Ambil tiket ini dulu sebelum kirim pesan ke toko.');
        $tickets->addMessage($ticket, $request->user(), $data['body'], true, $isInternal);

        return back()->with('status', 'Pesan terkirim.');
    }

    public function updateMessage(Request $request, MerchantTicket $ticket, MerchantTicketMessage $message, MerchantTicketService $tickets): RedirectResponse
    {
        $this->authorizeTicket($request->user(), $ticket);
        abort_unless($message->merchant_ticket_id === $ticket->id, 404);

        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        $tickets->editMessage($message, $request->user(), $data['body']);

        return back()->with('status', 'Pesan diperbarui.');
    }

    public function transfer(Request $request, MerchantTicket $ticket, MerchantTicketService $tickets): RedirectResponse
    {
        $this->authorizeTicket($request->user(), $ticket);
        $this->abortIfClaimedByAnother($request->user(), $ticket);
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
        $this->abortIfClaimedByAnother($request->user(), $ticket);
        abort_if($ticket->status === 'closed', 422, 'Tiket sudah ditutup.');

        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);
        $tickets->closeWithNote($ticket, $request->user(), $data['note'] ?? null);
        NotifyWaTicketLink::dispatch($ticket->id, 'closed', '');

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

    /**
     * Once a ticket is claimed, only that claimer may act on it (reply,
     * transfer, close) - otherwise two staff clicking the same WA link could
     * both work the same ticket at once. Superadmin bypasses for support/
     * override cases. Claim itself doesn't use this: it's already race-safe
     * via claim()'s atomic WHERE claimed_by_user_id IS NULL.
     */
    private function abortIfClaimedByAnother(User $user, MerchantTicket $ticket): void
    {
        if (! $ticket->claimed_by_user_id || (int) $ticket->claimed_by_user_id === (int) $user->id) {
            return;
        }
        if ($user->role === 'superadmin') {
            return;
        }
        abort(403, 'Tiket ini sudah diambil oleh '.($ticket->claimedBy?->name ?? 'orang lain').'.');
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
