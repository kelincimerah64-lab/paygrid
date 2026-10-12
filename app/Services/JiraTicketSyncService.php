<?php

namespace App\Services;

use App\Models\JiraTicketSync;
use App\Services\Jira\JiraClient;

class JiraTicketSyncService
{
    /**
     * Linear workflow order for this project - a ticket's Jira status only
     * ever moves forward along this list. transitionsNeeded() walks one step
     * at a time because Jira's transitions API only exposes moves reachable
     * from the issue's CURRENT status, so jumping straight from a fresh
     * "To Do" to "Done" (e.g. a ticket that was already resolved by the time
     * it's first synced) takes two transition calls, not one.
     */
    private const WORKFLOW_ORDER = ['To Do', 'In Progress', 'Done'];

    private const TERMINAL_STATUSES = ['RESOLVED', 'FAILED', 'CLOSED', 'REJECTED'];

    public function __construct(
        private readonly TelegramBotMonitoringService $sheet,
        private readonly JiraClient $jira,
    ) {}

    /**
     * $limit caps how many tickets get created/transitioned in this call -
     * null means no cap. Use a small limit for the first real run against a
     * sheet with a lot of pre-existing history, so it doesn't flood Jira
     * with hundreds of issues in one shot before anyone's reviewed the
     * output.
     *
     * @return array{created: int, transitioned: int, skipped: int}
     */
    public function sync(?int $limit = null): array
    {
        $data = $this->sheet->data([], forceRefresh: true);
        $created = 0;
        $transitioned = 0;
        $skipped = 0;
        $touched = 0;

        foreach ($data['tickets'] as $ticket) {
            $ticketId = (string) ($ticket['ticket_id'] ?? '');
            if ($ticketId === '') {
                continue;
            }

            $target = $this->targetStatus((string) ($ticket['status'] ?? ''));
            if ($target === null) {
                $skipped++;
                continue;
            }

            $existing = JiraTicketSync::query()->where('ticket_id', $ticketId)->first();
            $needsWork = ! $existing || $existing->last_synced_status !== $target;

            if (! $needsWork) {
                continue;
            }

            if ($limit !== null && $touched >= $limit) {
                break;
            }
            $touched++;

            if (! $existing) {
                $issueKey = $this->jira->createIssue(
                    $this->summaryFor($ticket),
                    $this->descriptionFor($ticket),
                );
                $existing = JiraTicketSync::query()->create([
                    'ticket_id' => $ticketId,
                    'jira_issue_key' => $issueKey,
                    'last_synced_status' => 'To Do',
                ]);
                $created++;
            }

            $moved = $this->stepToward($existing, $target);
            if ($moved) {
                $transitioned++;
            }
        }

        return ['created' => $created, 'transitioned' => $transitioned, 'skipped' => $skipped];
    }

    /**
     * null = ticket not taken yet (OPEN), stays out of Jira entirely.
     */
    private function targetStatus(string $sheetStatus): ?string
    {
        return match ($sheetStatus) {
            'OPEN' => null,
            'ASSIGNED', 'WAITING_APPROVAL' => 'To Do',
            'ON_PROGRESS' => 'In Progress',
            default => in_array($sheetStatus, self::TERMINAL_STATUSES, true) ? 'Done' : null,
        };
    }

    private function stepToward(JiraTicketSync $sync, string $target): bool
    {
        $currentIndex = array_search($sync->last_synced_status, self::WORKFLOW_ORDER, true);
        $targetIndex = array_search($target, self::WORKFLOW_ORDER, true);

        if ($currentIndex === false || $targetIndex === false || $targetIndex <= $currentIndex) {
            return false;
        }

        $moved = false;
        for ($i = $currentIndex + 1; $i <= $targetIndex; $i++) {
            if (! $this->jira->transitionIssueTo($sync->jira_issue_key, self::WORKFLOW_ORDER[$i])) {
                break;
            }
            $sync->update(['last_synced_status' => self::WORKFLOW_ORDER[$i]]);
            $moved = true;
        }

        return $moved;
    }

    private function summaryFor(array $ticket): string
    {
        $category = (string) ($ticket['category'] ?? '-');

        return "[{$ticket['ticket_id']}] {$category}";
    }

    private function descriptionFor(array $ticket): string
    {
        $lines = [
            'Kategori: '.($ticket['category'] ?: '-'),
            'Requester: '.($ticket['requester_name'] ?: '-'),
            'Diambil oleh (Telegram): '.($ticket['assigned_name'] ?: '-'),
            'Status saat ini di Bot: '.($ticket['status'] ?: '-'),
        ];

        if ($ticket['status'] === 'WAITING_APPROVAL') {
            $lines[] = '';
            $lines[] = '⏳ Menunggu approval.';
        }

        $lines[] = '';
        $lines[] = 'Deskripsi:';
        $lines[] = (string) ($ticket['description'] ?? '-');

        return implode("\n", $lines);
    }
}
