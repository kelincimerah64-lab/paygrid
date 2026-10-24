<?php

namespace Tests\Unit;

use App\Services\TelegramBotMonitoringService;
use Tests\Concerns\UsesBotTeleDatabase;
use Tests\TestCase;

class OverdueTicketsStatusTest extends TestCase
{
    use UsesBotTeleDatabase;

    public function test_overdue_tickets_excludes_resolved_and_failed_by_status_not_timestamp(): void
    {
        config()->set('paygrid.telegram_bot_monitoring.reminder_threshold_minutes', 15);

        $old = now()->subMinutes(30)->format('Y-m-d H:i:s');

        // No completed_at set on any row: the terminal check must rely on status alone.
        $this->seedBotTickets([
            ['ticket_id', 'created_at', 'requester_name', 'status', 'handling_minutes'],
            ['T-RESOLVED', $old, 'Budi', 'RESOLVED', '-25163'],
            ['T-FAILED', $old, 'Sari', 'FAILED', '100'],
            ['T-OPEN', $old, 'Dedi', 'OPEN', '50'],
            ['T-ASSIGNED', $old, 'Rina', 'ASSIGNED', '10'],
        ]);

        $overdue = app(TelegramBotMonitoringService::class)->overdueTickets();

        $this->assertSame(['T-OPEN', 'T-ASSIGNED'], $overdue->pluck('ticket_id')->all());
    }
}
