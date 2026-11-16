<?php

namespace App\Http\Controllers;

use App\Models\MerchantTicket;
use App\Services\AuditLogService;
use App\Services\MerchantTicketService;
use App\Services\Navigation\MenuBuilder;
use App\Services\NotificationReadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DepartmentTicketController extends Controller
{
    public function index(Request $request): View
    {
        $departments = $this->departmentsFor($request);
        $search = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', 'all');

        $tickets = MerchantTicket::query()
            ->with(['merchant', 'claimedBy'])
            ->whereIn('department', $departments)
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->when($search !== '', fn ($query) => $query->where(function ($nested) use ($search) {
                $nested->where('ticket_no', 'like', "{$search}%")
                    ->orWhereRelation('merchant', 'name', 'like', "{$search}%");
            }))
            ->latest('last_message_at')
            ->paginate(config('paygrid.reports.default_page_size', 50))
            ->withQueryString();

        $restricted = $this->departmentForRole($request->user()->role);
        app(NotificationReadService::class)->markSeen($request->user(), 'dept-tickets');

        return view('paygrid.dept-tickets', [
            'roleLabel' => $restricted !== null ? (MerchantTicketService::DEPARTMENT_LABELS[$restricted] ?? 'Tickets') : 'CS Pusat',
            'menus' => $restricted !== null ? app(MenuBuilder::class)->deptStaff() : app(MenuBuilder::class)->centerSupport($request->user()),
            'active' => $restricted !== null ? 'tickets' : 'merchant-support',
            'tickets' => $tickets,
            'search' => $search,
            'status' => $status,
            'categories' => app(MerchantTicketService::class),
        ]);
    }

    public function show(Request $request, MerchantTicket $ticket): View|RedirectResponse
    {
        $user = $request->user();
        abort_unless($this->canAccessDepartment($user, $ticket->department), 403);

        if ($this->claimedBySomeoneElse($user, $ticket)) {
            return redirect()->route('dept-tickets.index')
                ->with('status', 'Tiket '.$ticket->ticket_no.' sudah diambil oleh '.($ticket->claimedBy?->name ?? 'staff lain').'.');
        }

        $restricted = $this->departmentForRole($user->role);
        $roleLabel = $restricted !== null ? (MerchantTicketService::DEPARTMENT_LABELS[$restricted] ?? 'Tickets') : 'CS Pusat';
        $menus = $restricted !== null ? app(MenuBuilder::class)->deptStaff() : app(MenuBuilder::class)->centerSupport($user);

        if ($ticket->department === MerchantTicketService::SIMPLE_DEPARTMENT) {
            return view('paygrid.dept-ticket-show-toko', [
                'roleLabel' => $roleLabel,
                'menus' => $menus,
                'active' => $restricted !== null ? 'tickets' : 'merchant-support',
                'ticket' => $ticket->load(['merchant', 'createdBy', 'claimedBy']),
            ]);
        }

        return view('paygrid.dept-ticket-show', [
            'roleLabel' => $roleLabel,
            'menus' => $menus,
            'active' => $restricted !== null ? 'tickets' : 'merchant-support',
            'ticket' => $ticket->load(['messages.user', 'merchant', 'claimedBy']),
            'categories' => app(MerchantTicketService::class),
        ]);
    }

    public function reply(Request $request, MerchantTicket $ticket, MerchantTicketService $tickets): RedirectResponse
    {
        abort_unless($this->canAccessDepartment($request->user(), $ticket->department), 403);
        abort_if($ticket->department === MerchantTicketService::SIMPLE_DEPARTMENT, 404);

        if ($ticket->status === 'closed') {
            return back()->with('status', 'Tiket sudah ditutup.');
        }
        if (! $this->isClaimant($request->user(), $ticket)) {
            return back()->with('status', 'Ambil tiket ini dulu sebelum membalas.');
        }

        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        $tickets->addMessage($ticket, $request->user(), $data['body'], true);

        return back()->with('status', 'Balasan terkirim ke toko.');
    }

    /**
     * Atomic claim via MerchantTicketService::claim(); on conflict (someone
     * else claimed it a moment earlier) automatically find and claim a
     * different open/unclaimed ticket in the same department instead of just
     * bouncing the user back empty-handed.
     */
    public function claim(Request $request, MerchantTicket $ticket, MerchantTicketService $tickets): RedirectResponse
    {
        abort_unless($this->canAccessDepartment($request->user(), $ticket->department), 403);

        if ($tickets->claim($ticket, $request->user())) {
            return redirect()->route('dept-tickets.show', $ticket)->with('status', 'Tiket diambil.');
        }

        $skipIds = [$ticket->id];

        for ($attempts = 0; $attempts < 3; $attempts++) {
            $next = MerchantTicket::query()
                ->where('department', $ticket->department)
                ->where('status', 'open')
                ->whereNull('claimed_by_user_id')
                ->whereNotIn('id', $skipIds)
                ->inRandomOrder()
                ->first();

            if (! $next) {
                break;
            }

            if ($tickets->claim($next, $request->user())) {
                return redirect()->route('dept-tickets.show', $next)
                    ->with('status', 'Tiket '.$ticket->ticket_no.' sudah diambil orang lain - kamu dapat tiket lain: '.$next->ticket_no.'.');
            }

            $skipIds[] = $next->id;
        }

        return redirect()->route('dept-tickets.index')
            ->with('status', 'Tiket '.$ticket->ticket_no.' sudah diambil orang lain, dan tidak ada tiket lain yang tersedia saat ini.');
    }

    public function updateStatus(Request $request, MerchantTicket $ticket, AuditLogService $audit): RedirectResponse
    {
        abort_unless($this->canAccessDepartment($request->user(), $ticket->department), 403);

        // Toko tickets (SIMPLE_DEPARTMENT) are always handled by CS Pusat and
        // intentionally support reopening after close (see
        // MerchantTicketTest::test_toko_ticket_has_no_chat...) - the
        // claimant-only + no-reopen rules below are specific to the
        // claim-based department ticket workflow, not that one.
        if ($ticket->department !== MerchantTicketService::SIMPLE_DEPARTMENT) {
            if ($ticket->status === 'closed') {
                return back()->with('status', 'Tiket sudah ditutup dan tidak bisa diubah lagi.');
            }
            if (! $this->isClaimant($request->user(), $ticket)) {
                return back()->with('status', 'Ambil tiket ini dulu sebelum mengubah status.');
            }
        }

        $data = $request->validate([
            'status' => ['required', Rule::in(['open', 'in_progress', 'closed'])],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $before = $ticket->only(['status', 'closed_at', 'closed_by_user_id', 'internal_note', 'claimed_by_user_id']);
        $ticket->forceFill([
            'status' => $data['status'],
            'closed_at' => $data['status'] === 'closed' ? now() : null,
            'closed_by_user_id' => $data['status'] === 'closed' ? $request->user()->id : null,
        ]);

        if ($ticket->department === MerchantTicketService::SIMPLE_DEPARTMENT) {
            $ticket->forceFill([
                'internal_note' => $data['note'] ?? null,
                'claimed_by_user_id' => $request->user()->id,
                'claimed_at' => now(),
            ]);
        }

        $ticket->save();
        $audit->record('merchant_ticket.status_updated', $ticket, $before, $ticket->only(['status', 'closed_at', 'closed_by_user_id', 'internal_note', 'claimed_by_user_id']));

        return back()->with('status', 'Status tiket berhasil diubah.');
    }

    /**
     * Cross-department push - only the current claimant (or cs_pusat/
     * superadmin) may push, matching reply()/updateStatus()'s ownership
     * gate. Releases the claim (the new department's staff haven't claimed
     * it) and records who pushed it from where via
     * MerchantTicketService::pushToDepartment().
     */
    public function pushDepartment(Request $request, MerchantTicket $ticket, MerchantTicketService $tickets, AuditLogService $audit): RedirectResponse
    {
        abort_unless($this->canAccessDepartment($request->user(), $ticket->department), 403);
        abort_if($ticket->department === MerchantTicketService::SIMPLE_DEPARTMENT, 404);

        if ($ticket->status === 'closed') {
            return back()->with('status', 'Tiket sudah ditutup.');
        }
        if (! $this->isClaimant($request->user(), $ticket)) {
            return back()->with('status', 'Ambil tiket ini dulu sebelum memindahkan department.');
        }

        $data = $request->validate([
            'department' => ['required', Rule::in(MerchantTicketService::DEPARTMENTS)],
        ]);
        if ($data['department'] === $ticket->department) {
            return back()->with('status', 'Tiket sudah ada di department ini.');
        }

        $before = $ticket->only(['department', 'claimed_by_user_id']);
        $tickets->pushToDepartment($ticket, $request->user(), $data['department']);
        $audit->record('merchant_ticket.department_pushed', $ticket, $before, $ticket->only(['department', 'claimed_by_user_id']));

        return redirect()->route('dept-tickets.index')->with('status', 'Tiket dipindahkan ke '.$tickets->departmentLabel($data['department']).'.');
    }

    public function attachment(Request $request, MerchantTicket $ticket, int $index): StreamedResponse|RedirectResponse
    {
        abort_unless($this->canAccessDepartment($request->user(), $ticket->department), 403);

        if ($this->claimedBySomeoneElse($request->user(), $ticket)) {
            return redirect()->route('dept-tickets.index')
                ->with('status', 'Tiket '.$ticket->ticket_no.' sudah diambil oleh '.($ticket->claimedBy?->name ?? 'staff lain').'.');
        }

        $attachment = ($ticket->attachments ?? [])[$index] ?? null;
        abort_unless($attachment && isset($attachment['path']), 404);
        abort_unless(Storage::disk($attachment['disk'])->exists($attachment['path']), 404);

        $disk = Storage::disk($attachment['disk']);
        $name = $attachment['name'] ?? basename($attachment['path']);

        return $request->boolean('download')
            ? $disk->download($attachment['path'], $name)
            : $disk->response($attachment['path'], $name);
    }

    /**
     * CS Pusat/superadmin have one unified "Tiket Toko" queue covering every
     * department (including 'general', the simple merchant-facing ticket
     * type) unless narrowed via ?department=. A dept_* role (e.g. dept_fraud)
     * is always locked to its own single department, ignoring ?department=
     * entirely.
     */
    private function departmentsFor(Request $request): array
    {
        $restricted = $this->departmentForRole($request->user()->role);
        if ($restricted !== null) {
            return [$restricted];
        }

        $department = $request->query('department');
        $selectable = [...MerchantTicketService::DEPARTMENTS, MerchantTicketService::SIMPLE_DEPARTMENT];

        return in_array($department, $selectable, true) ? [$department] : $selectable;
    }

    /**
     * Used by show/reply/claim/updateStatus/attachment instead of
     * departmentsFor() - those act on one already-resolved ticket (not a
     * filtered list), so access should depend on which department the
     * ticket actually belongs to, not on whatever ?department= happened to
     * be on the current request/referrer.
     */
    private function canAccessDepartment($user, string $department): bool
    {
        if (! in_array($department, [...MerchantTicketService::DEPARTMENTS, MerchantTicketService::SIMPLE_DEPARTMENT], true)) {
            return false;
        }

        $restricted = $this->departmentForRole($user->role);

        return $restricted === null || $restricted === $department;
    }

    /**
     * True only when a dept_* staff member who ISN'T the claimant hits an
     * active (non-closed) ticket someone else already took. Used to show a
     * friendly "sudah diambil oleh ..." message instead of a hard 403 -
     * callers must still check canAccessDepartment() separately for genuine
     * cross-department access violations.
     */
    private function claimedBySomeoneElse($user, MerchantTicket $ticket): bool
    {
        if ($this->departmentForRole($user->role) === null || $ticket->status === 'closed') {
            return false;
        }

        return $ticket->claimed_by_user_id !== null && (int) $ticket->claimed_by_user_id !== (int) $user->id;
    }

    /**
     * Whoever currently holds the ticket, or CS Pusat/superadmin acting as
     * oversight. Used by reply()/updateStatus()/pushDepartment() to decide
     * whether to proceed or bounce back with a flash message.
     */
    private function isClaimant($user, MerchantTicket $ticket): bool
    {
        return in_array($user->role, ['cs_pusat', 'superadmin'], true) || (int) $ticket->claimed_by_user_id === (int) $user->id;
    }

    /**
     * dept_cs/dept_dev/dept_tech_supp/dept_finance/dept_settlement/dept_fraud/
     * dept_dispute each map to exactly one department by stripping the
     * 'dept_' prefix. cs_pusat/superadmin (and any other role) get null -
     * unrestricted, full visibility.
     */
    private function departmentForRole(string $role): ?string
    {
        return str_starts_with($role, 'dept_') ? substr($role, 5) : null;
    }
}
