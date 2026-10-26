<?php

namespace App\Services;

use App\Services\BotTele\BotTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramBotMonitoringService
{
    private const TERMINAL_STATUSES = ['RESOLVED', 'FAILED'];

    // The first 24 columns of the old "Tickets" sheet (range A:X), in sheet order.
    private const COLUMNS = [
        'ticket_id', 'created_at', 'source_chat_id', 'source_chat_title', 'requester_user_id', 'requester_name',
        'requester_username', 'category', 'description', 'status', 'assigned_user_id', 'assigned_name',
        'cs_group_message_id', 'merchant_message_id', 'attachment_file_id', 'has_attachment', 'assigned_at', 'started_at',
        'pickup_minutes', 'investigation_lag_minutes', 'handling_minutes', 'total_resolution_minutes', 'last_update', 'last_open_reminder_at',
    ];

    public function data(array $filters, bool $forceRefresh = false): array
    {
        $rows = $this->fetchRows($forceRefresh);

        if ($rows === null) {
            return [
                'headers' => [],
                'kpis' => $this->emptyKpis(),
                'tickets' => [],
                'categories' => [],
                'statuses' => [],
                'assignees' => [],
                'shifts' => $this->shifts(),
                'error' => 'Gagal mengambil data tiket bot. Coba refresh kembali.',
            ];
        }

        $all = $this->normalize($rows);
        $tickets = $this->filter($all, $filters)->sortByDesc('created_at');

        return [
            'headers' => $this->headers($rows),
            'kpis' => $this->kpis($tickets),
            'tickets' => $tickets->values()->all(),
            'categories' => $all->pluck('category')->filter()->unique()->sort()->values()->all(),
            'statuses' => $all->pluck('status')->filter()->unique()->sort()->values()->all(),
            'assignees' => $all->pluck('assigned_name')->filter()->unique()->sort()->values()->all(),
            'shifts' => $this->shifts(),
            'error' => null,
        ];
    }

    /**
     * Tech Support shifts: one row per technician from their latest `absen`. A shift lasts 8 hours from
     * check-in, so a technician is on shift while status is HADIR and expires_at is still ahead.
     */
    public function shifts(): array
    {
        try {
            $rows = DB::connection('bottele')->table('bot_attendances')->get(['name', 'username', 'status', 'check_in_at', 'expires_at', 'active_ticket_id']);
        } catch (Throwable $e) {
            Log::warning('telegram_bot_monitoring.shifts_failed', ['message' => $e->getMessage()]);

            return [];
        }

        $now = BotTime::now();

        return $rows->map(function (object $tech) use ($now): array {
            $checkIn = BotTime::parse($tech->check_in_at);
            $end = BotTime::parse($tech->expires_at);

            return [
                'name' => $tech->name ?: '-',
                'username' => $tech->username,
                'check_in_at' => $checkIn,
                'ends_at' => $end,
                'on_shift' => $tech->status === 'HADIR' && $end !== null && $end->greaterThan($now),
                'active_ticket_id' => $tech->active_ticket_id,
            ];
        })
            ->sortBy([['on_shift', 'desc'], fn (array $a, array $b) => ($b['check_in_at']?->timestamp ?? 0) <=> ($a['check_in_at']?->timestamp ?? 0)])
            ->values()
            ->all();
    }

    public function pendingIpWhitelist(): Collection
    {
        $rows = $this->fetchRows(false);

        if ($rows === null) {
            return collect();
        }

        return $this->normalize($rows)->filter(fn ($ticket) => str_contains(mb_strtolower((string) ($ticket['category'] ?? '')), 'whitelist')
            && $ticket['status'] === 'OPEN')->values();
    }

    public function overdueTickets(): Collection
    {
        $rows = $this->fetchRows(false);

        if ($rows === null) {
            return collect();
        }

        $threshold = (int) config('paygrid.telegram_bot_monitoring.reminder_threshold_minutes', 15);

        return $this->normalize($rows)->filter(fn ($ticket) => ! in_array($ticket['status'], self::TERMINAL_STATUSES, true)
            && $ticket['created_at'] !== null
            && $ticket['created_at']->diffInMinutes(CarbonImmutable::now()) >= $threshold)->values();
    }

    /**
     * Reads the bot's own database (written by Laravel since the n8n cutover) in the shape the
     * Google Sheet used to return: a header row followed by value rows, so normalize() is unchanged.
     * Not cached ($forceRefresh is kept for callers): the table is small and new tickets must show at once.
     */
    private function fetchRows(bool $forceRefresh): ?array
    {
        try {
            $rows = DB::connection('bottele')->table('bot_tickets')->orderBy('id')->get(self::COLUMNS)
                ->map(function (object $ticket): array {
                    $ticket->has_attachment = $ticket->has_attachment ? 'TRUE' : 'FALSE';

                    return array_values((array) $ticket);
                })
                ->all();
        } catch (Throwable $e) {
            Log::warning('telegram_bot_monitoring.fetch_failed', ['message' => $e->getMessage()]);

            return null;
        }

        return [self::COLUMNS, ...$rows];
    }

    private function normalize(array $rows): Collection
    {
        $rows = collect($rows);
        $headers = $this->headers($rows->all());

        if ($headers === []) {
            return collect();
        }

        $minuteFields = ['pickup_minutes', 'investigation_lag_minutes', 'handling_minutes', 'total_resolution_minutes'];
        $timestampFields = ['created_at', 'assigned_at', 'started_at', 'updated_at', 'completed_at'];

        return $rows->slice(1)->map(function (array $row) use ($headers, $minuteFields, $timestampFields) {
            $ticket = [];

            foreach ($headers as $index => $header) {
                $ticket[$header] = $row[$index] ?? null;
            }

            foreach ($minuteFields as $field) {
                $seconds = isset($ticket[$field]) && $ticket[$field] !== '' ? (int) $ticket[$field] : null;
                $ticket[$field] = $seconds !== null && $seconds >= 0 ? round($seconds / 60, 1) : null;
            }

            foreach ($timestampFields as $field) {
                $ticket[$field] = ! empty($ticket[$field])
                    ? CarbonImmutable::parse($ticket[$field])
                    : null;
            }

            $ticket['has_attachment'] = strtoupper((string) ($ticket['has_attachment'] ?? '')) === 'TRUE';
            $ticket['status'] = strtoupper((string) ($ticket['status'] ?? ''));
            $ticket['sheet_fields'] = collect($headers)->map(fn (string $header) => [
                'key' => $header,
                'label' => $this->columnLabel($header),
                'value' => $this->displayValue($header, $ticket[$header] ?? null),
            ])->all();

            return $ticket;
        })->values();
    }

    private function headers(array $rows): array
    {
        $headers = $rows[0] ?? null;

        if (! is_array($headers)) {
            return [];
        }

        return collect($headers)
            ->map(fn ($header) => trim((string) $header))
            ->filter(fn ($header) => $header !== '')
            ->values()
            ->all();
    }

    private function columnLabel(string $header): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $header));
    }

    private function displayValue(string $field, mixed $value): string
    {
        if ($value instanceof CarbonImmutable) {
            return $value->format('d/m/y H.i').' WIB';
        }

        if (is_bool($value)) {
            return $value ? 'Ya' : 'Tidak';
        }

        if ($value === null || $value === '') {
            return '-';
        }

        if (str_ends_with($field, '_minutes') && is_numeric($value)) {
            return rtrim(rtrim(number_format((float) $value, 1, ',', '.'), '0'), ',').' menit';
        }

        return (string) $value;
    }

    private function filter(Collection $tickets, array $filters): Collection
    {
        return $tickets
            ->when($filters['status'] ?? null, fn ($items, $status) => $items->filter(fn ($t) => $t['status'] === strtoupper($status)))
            ->when($filters['category'] ?? null, fn ($items, $category) => $items->filter(fn ($t) => ($t['category'] ?? null) === $category))
            ->when($filters['assigned_name'] ?? null, fn ($items, $assigned) => $items->filter(fn ($t) => ($t['assigned_name'] ?? null) === $assigned))
            ->when($filters['from'] ?? null, function ($items, $from) {
                $from = CarbonImmutable::parse($from)->startOfDay();

                return $items->filter(fn ($t) => $t['created_at'] && $t['created_at']->greaterThanOrEqualTo($from));
            })
            ->when($filters['to'] ?? null, function ($items, $to) {
                $to = CarbonImmutable::parse($to)->endOfDay();

                return $items->filter(fn ($t) => $t['created_at'] && $t['created_at']->lessThanOrEqualTo($to));
            })
            ->when($filters['q'] ?? null, function ($items, $q) {
                $needle = mb_strtolower($q);

                return $items->filter(function ($ticket) use ($needle) {
                    return collect($ticket['sheet_fields'] ?? [])
                        ->contains(fn ($field) => str_contains(mb_strtolower((string) ($field['value'] ?? '')), $needle));
                });
            })
            ->values();
    }

    private function kpis(Collection $tickets): array
    {
        $avg = fn (string $field) => $tickets->pluck($field)->filter(fn ($v) => $v !== null)->avg();

        return [
            'total' => $tickets->count(),
            'resolved' => $tickets->where('status', 'RESOLVED')->count(),
            'failed' => $tickets->where('status', 'FAILED')->count(),
            'avg_pickup_minutes' => round((float) ($avg('pickup_minutes') ?? 0), 1),
            'avg_handling_minutes' => round((float) ($avg('handling_minutes') ?? 0), 1),
            'avg_total_resolution_minutes' => round((float) ($avg('total_resolution_minutes') ?? 0), 1),
            'by_category' => $tickets->groupBy(fn ($t) => $t['category'] ?: '-')->map->count()->sortDesc()->all(),
            'by_status' => $tickets->groupBy('status')->map->count()->sortDesc()->all(),
            'top_assignees' => $tickets->groupBy(fn ($t) => $t['assigned_name'] ?: '-')->map->count()->sortDesc()->take(10)->all(),
        ];
    }

    private function emptyKpis(): array
    {
        return [
            'total' => 0,
            'resolved' => 0,
            'failed' => 0,
            'avg_pickup_minutes' => 0,
            'avg_handling_minutes' => 0,
            'avg_total_resolution_minutes' => 0,
            'by_category' => [],
            'by_status' => [],
            'top_assignees' => [],
        ];
    }
}
