<?php

namespace Tests\Unit;

use App\Services\TelegramBotMonitoringService;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UsesBotTeleDatabase;
use Tests\TestCase;

class TelegramBotMonitoringServiceTest extends TestCase
{
    use UsesBotTeleDatabase;

    public function test_it_normalizes_filters_and_computes_kpis_from_bot_tickets(): void
    {
        $this->seedBotTickets([
            ['ticket_id', 'created_at', 'requester_name', 'requester_username', 'category', 'status', 'assigned_name', 'pickup_minutes', 'handling_minutes', 'total_resolution_minutes', 'has_attachment', 'source_chat_id', 'last_update'],
            ['T-1', '2026-08-01 10:00:00', 'Budi', 'budi_x', 'API Error', 'RESOLVED', 'Agent A', '120', '600', '900', 1, '1001', 'Refund sudah clear'],
            ['T-2', '2026-08-02 11:00:00', 'Sari', 'sari_y', 'Dashboard Lemot', 'FAILED', 'Agent B', '', '', '', 0, '1002', 'Butuh follow up bank'],
            ['T-3', '2026-08-03 12:00:00', 'Budi', 'budi_x', 'API Error', 'RESOLVED', 'Agent A', '240', '1200', '1500', 0, '1003', 'Done by bot'],
        ]);

        $service = app(TelegramBotMonitoringService::class);
        $result = $service->data([]);

        $this->assertNull($result['error']);
        $this->assertSame(3, $result['kpis']['total']);
        $this->assertSame(2, $result['kpis']['resolved']);
        $this->assertSame(1, $result['kpis']['failed']);
        $this->assertEqualsWithDelta(3.0, $result['kpis']['avg_pickup_minutes'], 0.01);
        $this->assertSame(['API Error', 'Dashboard Lemot'], $result['categories']);
        $this->assertSame('ticket_id', $result['headers'][0]);
        $this->assertContains('last_update', $result['headers']);
        $this->assertSame('T-3', $result['tickets'][0]['ticket_id']);
        $this->assertSame('Done by bot', $result['tickets'][0]['last_update']);
        $this->assertContains(['key' => 'last_update', 'label' => 'Last Update', 'value' => 'Done by bot'], $result['tickets'][0]['sheet_fields']);
        $this->assertTrue($result['tickets'][2]['has_attachment']);
        $this->assertFalse($result['tickets'][1]['has_attachment']);

        $filtered = $service->data(['category' => 'API Error']);
        $this->assertSame(2, $filtered['kpis']['total']);
        $this->assertSame(0, $filtered['kpis']['failed']);

        $searched = $service->data(['q' => 'follow up bank']);
        $this->assertSame(1, $searched['kpis']['total']);
        $this->assertSame(1, $searched['kpis']['failed']);
    }

    public function test_a_ticket_saved_by_the_bot_shows_up_immediately(): void
    {
        $service = app(TelegramBotMonitoringService::class);
        $this->assertSame(0, $service->data([])['kpis']['total']);

        $this->seedBotTickets([['ticket_id', 'created_at', 'status'], ['T-NEW', '2026-10-24 12:37:00', 'OPEN']]);

        $this->assertSame(['T-NEW'], array_column($service->data([])['tickets'], 'ticket_id'));
    }

    public function test_it_treats_negative_raw_seconds_as_missing_instead_of_a_negative_duration(): void
    {
        $this->seedBotTickets([
            ['ticket_id', 'created_at', 'category', 'status', 'assigned_name', 'pickup_minutes', 'handling_minutes', 'total_resolution_minutes'],
            ['T-1', '2026-08-01 10:00:00', 'API Error', 'RESOLVED', 'Agent A', '120', '-25163', '300'],
        ]);

        $result = app(TelegramBotMonitoringService::class)->data([]);

        $this->assertNull($result['tickets'][0]['handling_minutes']);
        $this->assertSame(2.0, $result['tickets'][0]['pickup_minutes']);
        $this->assertSame(0.0, $result['kpis']['avg_handling_minutes']);
    }

    public function test_it_returns_open_ip_whitelist_tickets_pending_approval(): void
    {
        $this->seedBotTickets([
            ['ticket_id', 'created_at', 'requester_name', 'category', 'status'],
            ['T-1', '2026-08-01 10:00:00', 'Budi', 'IP Whitelist / VPN', 'OPEN'],
            ['T-2', '2026-08-02 11:00:00', 'Sari', 'Request IP Whitelist', 'RESOLVED'],
            ['T-3', '2026-08-03 12:00:00', 'Dedi', 'API Error', 'OPEN'],
        ]);

        $pending = app(TelegramBotMonitoringService::class)->pendingIpWhitelist();

        $this->assertCount(1, $pending);
        $this->assertSame('T-1', $pending->first()['ticket_id']);
    }

    public function test_it_returns_a_graceful_error_state_when_the_bot_database_fails(): void
    {
        DB::connection('bottele')->getSchemaBuilder()->drop('bot_tickets');

        $result = app(TelegramBotMonitoringService::class)->data([]);

        $this->assertNotNull($result['error']);
        $this->assertSame(0, $result['kpis']['total']);
        $this->assertSame([], $result['tickets']);
    }
}
