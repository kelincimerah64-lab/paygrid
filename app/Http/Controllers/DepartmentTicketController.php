<?php

namespace App\Http\Controllers;

use App\Jobs\NotifyWaTicketLink;
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
            'active' => $departments === [MerchantTicketService::SIMPLE_DEPARTMENT] ? 'merchant-support' : 'manual-tickets',
            'tickets' => $tickets,
            'search' => $search,
            'status' => $status,
            'categories' => app(MerchantTicketService::class),
        ]);
    }

    public function show(Request $request, MerchantTicket $ticket): View
    {
        abort_unless($this->isKnownDepartment($ticket->department), 403);

        if ($ticket->department === MerchantTicketService::SIMPLE_DEPARTMENT) {
            return view('paygrid.dept-ticket-show-toko', [
                'roleLabel' => 'CS Pusat',
                'menus' => app(MenuBuilder::class)->centerSupport(),
                'active' => 'merchant-support',
                'ticket' => $ticket->load(['merchant', 'createdBy', 'claimedBy']),
            ]);
        }

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
        abort_unless($this->isKnownDepartment($ticket->department), 403);
        abort_if($ticket->department === MerchantTicketService::SIMPLE_DEPARTMENT, 404);
        abort_if($ticket->status === 'closed', 422, 'Tiket sudah ditutup.');

        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        $tickets->addMessage($ticket, $request->user(), $data['body'], true);

        return back()->with('status', 'Balasan terkirim ke toko.');
    }

    public function updateStatus(Request $request, MerchantTicket $ticket, AuditLogService $audit): RedirectResponse
    {
        abort_unless($this->isKnownDepartment($ticket->department), 403);

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

        // Keep the WA Tiket dashboard's live card in sync even when status is
        // changed from here instead - editActiveCard() no-ops if this ticket
        // never had a WA card (not every ticket does), so this is safe either
        // way. 'general' tickets (the simple merchant-facing flow) are
        // deliberately excluded - they must never touch the WA group.
        if ($ticket->department !== MerchantTicketService::SIMPLE_DEPARTMENT) {
            NotifyWaTicketLink::dispatch($ticket->id, 'closed', '');
        }

        return back()->with('status', 'Status tiket berhasil diubah.');
    }

    public function attachment(Request $request, MerchantTicket $ticket, int $index): StreamedResponse
    {
        abort_unless($this->isKnownDepartment($ticket->department), 403);

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
     * 'general' (the simple merchant-facing ticket type) is deliberately left
     * out of the "no filter" default - it has its own dedicated CS Pusat menu
     * entry instead of being mixed into the default cs/tech/finance queue.
     * It's still explicitly selectable via ?department=general.
     */
    private function departmentsFor(Request $request): array
    {
        $department = $request->query('department');
        $selectable = [...MerchantTicketService::DEPARTMENTS, MerchantTicketService::SIMPLE_DEPARTMENT];

        return in_array($department, $selectable, true) ? [$department] : MerchantTicketService::DEPARTMENTS;
    }

    /**
     * Used by show/reply/updateStatus instead of departmentsFor() - those act
     * on one already-resolved ticket (not a filtered list), so access should
     * depend on which department the ticket actually belongs to, not on
     * whatever ?department= happened to be on the current request/referrer.
     */
    private function isKnownDepartment(string $department): bool
    {
        return in_array($department, [...MerchantTicketService::DEPARTMENTS, MerchantTicketService::SIMPLE_DEPARTMENT], true);
    }
}
