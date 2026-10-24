<?php

namespace App\Console\Commands;

use App\Models\BotAttendance;
use App\Models\BotSession;
use App\Models\BotTicket;
use App\Services\BotTele\BotTime;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * One-off migration of the Bot_Tele Google Sheet export into the bottele DB.
 * Same shape as paygrid:import-billway: dry-run by default, --commit to write.
 * Upserts by business key so it is safe to re-run, and never fails a row for
 * dirty values: an unparseable number/date becomes NULL plus a warning.
 */
class ImportBotTeleSheetsCommand extends Command
{
    protected $signature = 'bottele:import-sheets
        {--file= : Path to the Bot_Tele.xlsx export}
        {--commit : Persist changes. Omit for dry-run preview}';

    protected $description = 'Import historical Bot_Tele tickets, sessions, and attendance from the Google Sheets xlsx export.';

    private const SHEETS = [
        'Tickets' => [
            'model' => BotTicket::class,
            'key' => ['ticket_id'],
            'columns' => [
                'ticket_id' => 'string', 'created_at' => 'datetime', 'source_chat_id' => 'int', 'source_chat_title' => 'string',
                'requester_user_id' => 'int', 'requester_name' => 'string', 'requester_username' => 'string', 'category' => 'string',
                'description' => 'string', 'status' => 'string', 'assigned_user_id' => 'int', 'assigned_name' => 'string',
                'cs_group_message_id' => 'int', 'merchant_message_id' => 'int', 'attachment_file_id' => 'string', 'has_attachment' => 'bool',
                'assigned_at' => 'datetime', 'started_at' => 'datetime', 'pickup_minutes' => 'int', 'investigation_lag_minutes' => 'int',
                'handling_minutes' => 'int', 'total_resolution_minutes' => 'int', 'last_update' => 'string', 'last_open_reminder_at' => 'datetime',
                'open_reminder_message_ids' => 'string', 'open_reminder_count' => 'int', 'assignment_type' => 'string', 'auto_assigned_at' => 'datetime',
                'auto_assigned_message_id' => 'int', 'escalated' => 'nullable_bool', 'escalated_at' => 'datetime', 'approval_status' => 'string',
                'approval_by_user_id' => 'int', 'approval_by_name' => 'string', 'approval_note' => 'string', 'approval_requested_at' => 'datetime',
                'approval_completed_at' => 'datetime', 'approval_group_message_id' => 'string', 'updated_at' => 'datetime', 'completed_at' => 'datetime',
            ],
        ],
        'session' => [
            'model' => BotSession::class,
            'key' => ['user_id', 'chat_id'],
            'columns' => [
                'user_id' => 'int', 'chat_id' => 'int', 'support_command_message_id' => 'int', 'support_menu_message_id' => 'int',
                'support_prompt_message_id' => 'int', 'user_detail_message_id' => 'int', 'state' => 'string', 'category' => 'string',
                'temp_ticket_id' => 'string', 'resolve_chat_id' => 'int', 'resolve_message_id' => 'int', 'updated_at' => 'datetime',
            ],
        ],
        'attendance' => [
            'model' => BotAttendance::class,
            'key' => ['user_id'],
            'columns' => [
                'user_id' => 'int', 'chat_id' => 'int', 'username' => 'string', 'name' => 'string', 'status' => 'string',
                'check_in_at' => 'datetime', 'expires_at' => 'datetime', 'active_ticket_id' => 'string', 'expiry_notified' => 'bool',
                'last_auto_assigned_at' => 'datetime', 'updated_at' => 'datetime',
            ],
        ],
    ];

    /** @var list<string> */
    private array $warnings = [];

    public function handle(): int
    {
        $file = (string) $this->option('file');
        if ($file === '' || ! is_file($file)) {
            $this->error('Spreadsheet not found. Pass --file=path/to/Bot_Tele.xlsx.');

            return self::FAILURE;
        }

        $commit = (bool) $this->option('commit');
        if ($commit && ! $this->confirm('This will write Bot_Tele history into the bottele database. Continue?', false)) {
            $this->warn('Import cancelled.');

            return self::SUCCESS;
        }

        $reader = IOFactory::createReaderForFile($file);
        $reader->setReadDataOnly(true);
        $workbook = $reader->load($file);

        $this->info(($commit ? 'Commit' : 'Dry-run').' Bot_Tele import');

        $summary = [];
        $errors = [];
        foreach (self::SHEETS as $sheetName => $spec) {
            $sheet = $workbook->getSheetByName($sheetName);
            if (! $sheet) {
                $this->warnings[] = "Sheet \"{$sheetName}\" not found in the workbook; skipped.";
                $summary[] = [$sheetName, 0, 0, 0, 0, 0];

                continue;
            }

            $run = fn (): array => $this->importSheet($sheetName, $spec, $sheet->toArray(null, true, false, false), $commit);
            $result = $commit ? DB::connection('bottele')->transaction($run) : $run();

            $summary[] = [$sheetName, $result['rows'], $result['created'], $result['updated'], $result['skipped'], count($result['errors'])];
            array_push($errors, ...$result['errors']);
        }

        $this->table(['Sheet', 'Rows', $commit ? 'Created' : 'Would create', $commit ? 'Updated' : 'Would update', 'Skipped', 'Errors'], $summary);

        foreach ($this->warnings as $warning) {
            $this->warn($warning);
        }
        foreach ($errors as $error) {
            $this->error($error);
        }

        if (! $commit) {
            $this->line('No data was written. Re-run with --commit after reviewing the preview.');
        }

        return self::SUCCESS;
    }

    /** @return array{rows: int, created: int, updated: int, skipped: int, errors: list<string>} */
    private function importSheet(string $sheetName, array $spec, array $rows, bool $commit): array
    {
        $header = array_map(fn ($cell): string => trim((string) $cell), array_shift($rows) ?? []);
        $unknown = array_diff(array_filter($header), array_keys($spec['columns']), ['row_number']);
        if ($unknown !== []) {
            $this->warnings[] = "{$sheetName}: ignored unknown column(s) ".implode(', ', $unknown).'.';
        }

        $result = ['rows' => count($rows), 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
        $records = [];

        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $raw = [];
            foreach ($header as $column => $name) {
                if (isset($spec['columns'][$name])) {
                    $raw[$name] = $row[$column] ?? null;
                }
            }

            // Google Sheets exports keep fully blank rows between real ones; drop them, and any row without its key.
            $values = [];
            foreach ($raw as $name => $value) {
                $values[$name] = $this->convert($spec['columns'][$name], $value, "{$sheetName} row {$line} ({$name})");
            }
            $key = array_intersect_key($values, array_flip($spec['key']));
            if (count(array_filter($key, fn ($v): bool => $v !== null && $v !== '')) !== count($spec['key'])) {
                $result['skipped']++;
                if (array_filter($raw, fn ($v): bool => $v !== null && $v !== '') !== []) {
                    $this->warnings[] = "{$sheetName} row {$line}: has data but no ".implode('/', $spec['key']).', skipped.';
                }

                continue;
            }

            // n8n appended duplicate session rows; keep the most recently updated one per key, like "Pick Latest * Session".
            $id = implode('|', $key);
            if (isset($records[$id])) {
                $result['skipped']++;
                if ((string) ($values['updated_at'] ?? '') < (string) ($records[$id]['values']['updated_at'] ?? '')) {
                    continue;
                }
            }
            $records[$id] = ['line' => $line, 'key' => $key, 'values' => $values];
        }

        /** @var class-string<Model> $model */
        $model = $spec['model'];
        foreach ($records as $record) {
            try {
                $exists = $model::query()->where($record['key'])->exists();
                if ($commit) {
                    $model::query()->updateOrCreate($record['key'], $record['values']);
                }
                $result[$exists ? 'updated' : 'created']++;
            } catch (\Throwable $exception) {
                $result['errors'][] = "{$sheetName} row {$record['line']}: ".$exception->getMessage();
            }
        }

        return $result;
    }

    private function convert(string $type, mixed $value, string $where): mixed
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return match ($type) {
                'bool' => false,
                default => null,
            };
        }

        return match ($type) {
            'int' => $this->toInt($value, $where),
            'bool', 'nullable_bool' => in_array(strtoupper(trim((string) $value)), ['TRUE', '1', 'YES'], true) || $value === true,
            'datetime' => $this->toDateTime($value, $where),
            default => is_string($value) ? $value : (string) $value,
        };
    }

    private function toInt(mixed $value, string $where): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int) round($value);
        }

        $text = trim((string) $value);
        if (preg_match('/^-?\d+(\.\d+)?$/', $text)) {
            return (int) round((float) $text);
        }

        // e.g. total_resolution_minutes holding a datetime string on rows written by the old n8n FAILED bug.
        $this->warnings[] = "{$where}: \"{$text}\" is not a number, stored NULL.";

        return null;
    }

    private function toDateTime(mixed $value, string $where): ?string
    {
        if (is_int($value) || is_float($value)) {
            return ExcelDate::excelToDateTimeObject($value)->format(BotTime::FORMAT);
        }

        $parsed = BotTime::parse($value);
        if (! $parsed) {
            $this->warnings[] = "{$where}: \"{$value}\" is not a date, stored NULL.";

            return null;
        }

        return $parsed->format(BotTime::FORMAT);
    }
}
