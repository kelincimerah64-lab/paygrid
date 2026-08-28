<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use App\Services\AuditLogService;
use App\Services\Navigation\MenuBuilder;
use App\Services\TelegramBotMonitoringService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\View\View;

class CenterSupportController extends Controller
{
    public const STATUSES = [
        'not_started' => 'Not Started',
        'checking' => 'Checking',
        'issue_bank' => 'Issue Bank',
        'success' => 'Success',
        'issue_switching' => 'Issue Switching',
    ];

    public function index(Request $request, TelegramBotMonitoringService $service): View
    {
        $search = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', 'all');
        $delivery = (string) $request->query('delivery', 'all');

        $tickets = SupportTicket::query()
            ->with(['merchant', 'topupRequest', 'centerUpdatedBy'])
            ->whereNotNull('submitted_to_center_at')
            ->when($status !== 'all', fn ($query) => $query->where('center_status', $status))
            ->when($delivery === 'sent', fn ($query) => $query->whereNotNull('center_updated_at'))
            ->when($delivery === 'pending', fn ($query) => $query->whereNull('center_updated_at'))
            ->when($search !== '', fn ($query) => $query->where(function ($nested) use ($search) {
                $nested->where('ticket_no', 'like', "{$search}%")
                    ->orWhere('reference', 'like', "{$search}%")
                    ->orWhere('client_reference', 'like', "{$search}%")
                    ->orWhereRelation('merchant', 'name', 'like', "{$search}%")
                    ->orWhereRelation('topupRequest', 'payment_id', 'like', "{$search}%")
                    ->orWhereRelation('topupRequest', 'rrn', 'like', "{$search}%");
            }))
            ->oldest('submitted_to_center_at')
            ->simplePaginate(config('paygrid.reports.default_page_size', 50))
            ->withQueryString();

        return view('paygrid.center-support', [
            'roleLabel' => 'CS Pusat',
            'menus' => app(MenuBuilder::class)->centerSupport(),
            'active' => 'tickets',
            'tickets' => $tickets,
            'statuses' => self::STATUSES,
            'search' => $search,
            'status' => $status,
            'delivery' => $delivery,
            'maNotifications' => $this->botReminders($service),
        ]);
    }

    public function botMonitoring(Request $request, TelegramBotMonitoringService $service): View
    {
        return view('paygrid.cs-pusat-bot-monitoring', [
            'roleLabel' => 'CS Pusat',
            'menus' => app(MenuBuilder::class)->centerSupport(),
            'active' => 'bot-monitoring',
            'botMonitoring' => $service->data($this->botMonitoringFilters(), $request->boolean('refresh')),
            'maNotifications' => $this->botReminders($service),
        ]);
    }

    private function botReminders(TelegramBotMonitoringService $service): Collection
    {
        return $service->overdueTickets()->take(10)->map(fn ($ticket) => (object) [
            'data' => [
                'title' => 'Reminder ticket bot Telegram',
                'message' => ($ticket['requester_name'] ?: 'Requester').' menunggu tindak lanjut untuk ticket '.$ticket['ticket_id'].'.',
                'url' => route('center-support.bot-monitoring', ['bot_q' => $ticket['ticket_id']]),
            ],
        ]);
    }

    private function botMonitoringFilters(): array
    {
        return [
            'status' => trim((string) request('bot_status', '')),
            'category' => trim((string) request('bot_category', '')),
            'assigned_name' => trim((string) request('bot_assigned', '')),
            'from' => request('bot_from'),
            'to' => request('bot_to'),
            'q' => trim((string) request('bot_q', '')),
        ];
    }

    public function update(Request $request, SupportTicket $ticket, AuditLogService $audit): RedirectResponse
    {
        abort_unless($ticket->submitted_to_center_at, 404);
        if ($ticket->center_updated_at) {
            return back()->with('status', 'Tiket sudah terkirim dari CS pusat dan tidak bisa dikirim ulang.');
        }

        $data = $request->validate([
            'center_status' => ['required', 'in:'.implode(',', array_keys(self::STATUSES))],
            'center_note' => ['nullable', 'string', 'max:1000'],
        ]);
        $before = $ticket->only(['center_status', 'center_note', 'center_updated_by_user_id', 'center_updated_at', 'status', 'closed_at']);
        $ticket->forceFill([
            'center_status' => $data['center_status'],
            'center_note' => $data['center_note'] ?? null,
            'center_updated_by_user_id' => $request->user()->id,
            'center_updated_at' => now(),
            'status' => $data['center_status'] === 'success' ? 'done' : 'in_progress',
            'closed_at' => $data['center_status'] === 'success' ? now() : null,
        ])->save();
        $audit->record('center_ticket.updated', $ticket, $before, $ticket->only(array_keys($before)));

        return back()->with('status', 'Status tiket berhasil disimpan dan tersambung ke tiket toko.');
    }

    public static function evidenceUrl(SupportTicket $ticket): ?string
    {
        return count($ticket->attachments ?? []) ? route('center-support.tickets.attachment', [$ticket, 0]) : null;
    }

    public function attachment(Request $request, SupportTicket $ticket, int $index): StreamedResponse
    {
        abort_unless($ticket->submitted_to_center_at, 404);

        $attachment = ($ticket->attachments ?? [])[$index] ?? null;
        abort_unless($attachment && isset($attachment['path']), 404);

        $disk = $attachment['disk'] ?? 'public';
        abort_unless(in_array($disk, ['local', 'public'], true), 404);
        abort_unless(Storage::disk($disk)->exists($attachment['path']), 404);

        return Storage::disk($disk)->download($attachment['path'], $attachment['name'] ?? basename($attachment['path']));
    }
}
