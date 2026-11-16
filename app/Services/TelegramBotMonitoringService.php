<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramBotMonitoringService
{
    private const TOKEN_CACHE_KEY = 'telegram-bot-sheet:access-token';

    private const DATA_CACHE_KEY = 'telegram-bot-sheet:rows';

    private const TERMINAL_STATUSES = ['RESOLVED', 'FAILED'];

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
                'error' => 'Gagal mengambil data dari Google Sheets. Coba refresh kembali.',
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
            'error' => null,
        ];
    }

    /**
     * CSV export of the same filtered/sorted ticket list the dashboard table
     * shows - same columns (in sheet order) and same formatted values
     * (dates, Ya/Tidak, "X menit") as the on-screen Detail panel, via the
     * already-built sheet_fields rather than re-deriving formatting here.
     */
    public function toCsv(array $filters): string
    {
        $data = $this->data($filters);
        $labels = collect($data['headers'])->map(fn (string $header) => $this->columnLabel($header))->all();

        $escape = fn ($value) => '"'.str_replace('"', '""', (string) $value).'"';

        $csv = implode(',', array_map($escape, $labels))."\n";
        foreach ($data['tickets'] as $ticket) {
            $values = collect($ticket['sheet_fields'] ?? [])->pluck('value')->all();
            $csv .= implode(',', array_map($escape, $values))."\n";
        }

        return $csv;
    }

    /**
     * Reject a ticket: marks it REJECTED in the sheet (row is kept for history/audit,
     * not deleted) and returns the Telegram message/chat ids the caller needs to delete
     * the ticket card in the CS group and the ack message in the requester's source chat.
     *
     * @return array{previous_status: ?string, cs_group_message_id: ?string, merchant_message_id: ?string, source_chat_id: ?string}
     */
    public function reject(string $ticketId): array
    {
        $raw = $this->fetchRawRows();

        if ($raw === null || $raw === []) {
            throw new \RuntimeException('Gagal mengambil data dari Google Sheets.');
        }

        $headers = $this->headers($raw);
        $colIndex = array_flip($headers);

        if (! isset($colIndex['ticket_id'])) {
            throw new \RuntimeException('Kolom ticket_id tidak ditemukan di sheet.');
        }

        $rowIndex = null;
        foreach ($raw as $i => $row) {
            if ($i === 0) {
                continue;
            }
            if (($row[$colIndex['ticket_id']] ?? null) === $ticketId) {
                $rowIndex = $i;
                break;
            }
        }

        if ($rowIndex === null) {
            throw new \RuntimeException("Ticket {$ticketId} tidak ditemukan di sheet.");
        }

        $row = $raw[$rowIndex];
        $get = fn (string $field) => isset($colIndex[$field]) ? ($row[$colIndex[$field]] ?? null) : null;

        $result = [
            'previous_status' => $get('status'),
            'cs_group_message_id' => $get('cs_group_message_id') ?: null,
            'merchant_message_id' => $get('merchant_message_id') ?: null,
            'source_chat_id' => $get('source_chat_id') ?: null,
        ];

        $now = now('Asia/Jakarta')->format('Y-m-d H:i:s');

        $this->writeCells($rowIndex + 1, $colIndex, [
            'status' => 'REJECTED',
            'updated_at' => $now,
            'completed_at' => $now,
            'last_update' => 'Ticket direject oleh CS Pusat (duplikat/tidak valid).',
        ]);

        Cache::forget(self::DATA_CACHE_KEY);

        return $result;
    }

    public function deleteTelegramMessage(string|int|null $chatId, string|int|null $messageId): bool
    {
        $token = config('paygrid.telegram_bot_monitoring.ticket_bot_token');

        if (! $token || ! $chatId || ! $messageId) {
            return false;
        }

        try {
            $response = Http::timeout(10)->post("https://api.telegram.org/bot{$token}/deleteMessage", [
                'chat_id' => $chatId,
                'message_id' => (int) $messageId,
            ]);

            if (! $response->successful()) {
                Log::warning('telegram_bot_monitoring.delete_message_failed', [
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                    'status' => $response->status(),
                ]);
            }

            return $response->successful();
        } catch (Throwable $e) {
            Log::warning('telegram_bot_monitoring.delete_message_failed', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
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
     * Raw sheet rows including the header row at index 0 - used when a row's exact
     * sheet position is needed (reject()), unlike fetchRows() which callers only read.
     */
    private function fetchRawRows(): ?array
    {
        return $this->fetchRows(false);
    }

    private function writeCells(int $sheetRowNumber, array $colIndex, array $fieldValues): void
    {
        $data = [];

        foreach ($fieldValues as $field => $value) {
            if (! isset($colIndex[$field])) {
                continue;
            }

            $column = $this->columnLetter($colIndex[$field]);
            $data[] = ['range' => "{$column}{$sheetRowNumber}", 'values' => [[$value]]];
        }

        if ($data === []) {
            return;
        }

        $spreadsheetId = config('paygrid.telegram_bot_monitoring.spreadsheet_id');
        $token = $this->accessToken();

        $response = Http::withToken($token)->timeout(10)->post(
            "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}/values:batchUpdate",
            ['valueInputOption' => 'USER_ENTERED', 'data' => $data]
        );

        if (! $response->successful()) {
            throw new \RuntimeException('Gagal update Google Sheets: '.$response->status());
        }
    }

    private function columnLetter(int $zeroBasedIndex): string
    {
        $letter = '';
        $index = $zeroBasedIndex + 1;

        while ($index > 0) {
            $index--;
            $letter = chr(65 + ($index % 26)).$letter;
            $index = intdiv($index, 26);
        }

        return $letter;
    }

    private function fetchRows(bool $forceRefresh): ?array
    {
        if ($forceRefresh) {
            Cache::forget(self::DATA_CACHE_KEY);
        }

        $ttl = (int) config('paygrid.telegram_bot_monitoring.cache_ttl_seconds', 45);

        try {
            return Cache::remember(self::DATA_CACHE_KEY, $ttl, function () {
                $spreadsheetId = config('paygrid.telegram_bot_monitoring.spreadsheet_id');
                $range = config('paygrid.telegram_bot_monitoring.sheet_range');
                $token = $this->accessToken();

                $response = Http::withToken($token)
                    ->timeout(10)
                    ->get("https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}/values/{$range}");

                if (! $response->successful()) {
                    throw new \RuntimeException('Sheets API returned '.$response->status());
                }

                return $response->json('values') ?? [];
            });
        } catch (Throwable $e) {
            Log::warning('telegram_bot_monitoring.fetch_failed', ['message' => $e->getMessage()]);
            Cache::forget(self::DATA_CACHE_KEY);

            return null;
        }
    }

    private function accessToken(): string
    {
        return Cache::remember(self::TOKEN_CACHE_KEY, 3300, function () {
            $email = config('paygrid.telegram_bot_monitoring.service_account_email');
            $privateKey = str_replace('\\n', "\n", (string) config('paygrid.telegram_bot_monitoring.service_account_private_key'));

            if (! $email || ! $privateKey) {
                throw new \RuntimeException('Google service account credentials are not configured.');
            }

            $now = time();
            $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claims = $this->base64UrlEncode(json_encode([
                'iss' => $email,
                'scope' => 'https://www.googleapis.com/auth/spreadsheets',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ]));

            $signature = '';
            $signed = openssl_sign("{$header}.{$claims}", $signature, $privateKey, OPENSSL_ALGO_SHA256);

            if (! $signed) {
                throw new \RuntimeException('Failed to sign Google service account JWT.');
            }

            $jwt = "{$header}.{$claims}.".$this->base64UrlEncode($signature);

            $response = Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            if (! $response->successful()) {
                throw new \RuntimeException('Failed to obtain Google OAuth2 token: '.$response->status());
            }

            return (string) $response->json('access_token');
        });
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
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
