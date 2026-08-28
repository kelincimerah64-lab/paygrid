<?php

namespace Tests\Unit;

use App\Services\TelegramBotMonitoringService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramBotMonitoringServiceTest extends TestCase
{
    /**
     * Throwaway RSA key generated solely for this test suite (openssl genrsa 2048) — never used
     * outside tests and grants access to nothing, so it is safe to keep in the repository.
     */
    private function fakePrivateKeyPem(): string
    {
        return <<<'PEM'
        -----BEGIN PRIVATE KEY-----
        MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQDZMFIuEPtiGZwE
        7E/q0Djb4TtHWbJJUN/eJhbYAc3gOXYPE3btFzEBvDBdG3ck7KOGRYkcSbAy9lvN
        a6kKFia3IsA1V8PXJWkNYSsdJDjPSgPlp9WiwNMXr8v6RTv8wzTqUPrtfhdWVkaf
        UZED+AdR5fQu/8tRZf/eBDORJeF0Zx5eqBYhn5xp/8N4c2X18DQvOkuVqhsxHgWN
        CLk+iKdtpybqB6O51asnlpPuiVDSDhq2nOGWBRkOXjZVMSTGl03FSazhLzmrMJzQ
        s4I+pIuauhmdFs7hAsYciA5w7OjQYH+j+kGWbjwDqzGWneoomXr85s/oidwSuVk7
        TQm6AvYXAgMBAAECggEAY0rBj1jpLECsAN4ubRnznKZ8TNLXfMgyCKQeNnOgtP1g
        GWVbLeo31+S6sZ5QWnurCMQ6ekm/+ZSChMfO+JMG8Ru8hsaQfIgYXmsJZGG+bRoX
        7QLNWwJPn1kZ5lmHord1thf+l9vY/HomAEkwhIF8izcXavM7dwOsNcpy7s1EJMv1
        uUPSuJ2vZaMx31nV4n6+aPHW/i9kThxa4qF4scahsBvBEhaYdWhRRDoG1htzUwtP
        5UEOuCcFv3zAOY4OLx8HAXmMoWktMVz2tmi0wv1fIqMMma/k3JOYPxwfGPId1vRn
        twz3MfYGKyzsdGi21mLBmhgqIvRpwf8G/xwooGK7pQKBgQD9z2H4Eb/71W4ETOv9
        0/9qFojIIHnhTt+16AnJuMmWx84RFjyv/HOM9aL8x4fVspF5nhaDGdih2OusX59l
        9dcfETZJJx/HtUDmmx8EV7IUBDWQGIP9DhrMwidCikxg+BJzGJII+57QE8p7zqG7
        MpKZ6+VG/p+TM8t+a7hdQpjjkwKBgQDbEAyEIxGxRe1DwGPj78e58jUvmpMvro2l
        G+ZHPYkjU/Fd22rKNub2lVhHuko6SfWZl+UnzdRZYoOrHN/99M9j5EPed0rlP6JG
        LXNqVYJcUIIo6xRa5EketPukni6yYLrHLHypR23H0hmgdu/L3zipod43CXbuN3IT
        63a4OjD97QKBgQCyhvbwWPvjleLG35x3dHEKHEOmEUHpu2McPtTzsSkLCAvodO3H
        FBnrIrS8fVUMeYheNVa8bKe2YDCVlMU4IM5qKd83YW+3N8Uo8B/HHDBEaBmM+9GL
        ZCpxsHeRFFpZMuU3VCcUbnjs/57DqzqTxCTeY9FoOJ3iGuKeUALkhn2oRQKBgDzm
        AkS9pw58HRCHrH1STFjSD50TQLWxtejfj63gWn56uI/aDp72klCchfUywa3gn6k6
        Q9dD7jOHIolwNojYBMuFSqTOzwBaJ1eRDRPTf7EAJJ8RcxAthHJH5+kEnIC0SVhT
        crhwhgFV1A/64IDxpkPqHud39xsUSN9mxxNCAhqlAoGAJJ0PZdoDGhWk6oVlIbpb
        bGxRGXQQheZbr39t4KlJVJNGd5RBDMYm4wTz2annRDgD2d1Q95EsNQ9U1oRqMY/1
        BozSWFbm8tudlvyCgXyNS7dGNFlLuoUPuP7X/CbyqhUF3gC0gID70ijgM3BJeuTQ
        CmO344btHTQmoNeDMYCcTE4=
        -----END PRIVATE KEY-----
        PEM;
    }

    private function configureCredentials(): void
    {
        config()->set('paygrid.telegram_bot_monitoring.spreadsheet_id', 'sheet-123');
        config()->set('paygrid.telegram_bot_monitoring.sheet_range', 'A:X');
        config()->set('paygrid.telegram_bot_monitoring.service_account_email', 'bot@test.iam.gserviceaccount.com');
        config()->set('paygrid.telegram_bot_monitoring.service_account_private_key', $this->fakePrivateKeyPem());
    }

    public function test_it_normalizes_filters_and_computes_kpis_from_sheet_rows(): void
    {
        Cache::flush();
        $this->configureCredentials();

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]),
            'https://sheets.googleapis.com/v4/spreadsheets/sheet-123/values/A:X' => Http::response([
                'values' => [
                    ['ticket_id', 'created_at', 'requester_name', 'requester_username', 'category', 'status', 'assigned_name', 'pickup_minutes', 'handling_minutes', 'total_resolution_minutes', 'has_attachment', 'telegram_chat_id', 'last_note'],
                    ['T-1', '2026-08-01 10:00:00', 'Budi', 'budi_x', 'API Error', 'RESOLVED', 'Agent A', '120', '600', '900', 'TRUE', '1001', 'Refund sudah clear'],
                    ['T-2', '2026-08-02 11:00:00', 'Sari', 'sari_y', 'Dashboard Lemot', 'FAILED', 'Agent B', '', '', '', 'FALSE', '1002', 'Butuh follow up bank'],
                    ['T-3', '2026-08-03 12:00:00', 'Budi', 'budi_x', 'API Error', 'RESOLVED', 'Agent A', '240', '1200', '1500', 'FALSE', '1003', 'Done by bot'], 
                ],
            ]),
        ]);

        $service = app(TelegramBotMonitoringService::class);
        $result = $service->data([]);

        $this->assertNull($result['error']);
        $this->assertSame(3, $result['kpis']['total']);
        $this->assertSame(2, $result['kpis']['resolved']);
        $this->assertSame(1, $result['kpis']['failed']);
        $this->assertEqualsWithDelta(3.0, $result['kpis']['avg_pickup_minutes'], 0.01);
        $this->assertSame(['API Error', 'Dashboard Lemot'], $result['categories']);
        $this->assertSame(['ticket_id', 'created_at', 'requester_name', 'requester_username', 'category', 'status', 'assigned_name', 'pickup_minutes', 'handling_minutes', 'total_resolution_minutes', 'has_attachment', 'telegram_chat_id', 'last_note'], $result['headers']);
        $this->assertSame('Refund sudah clear', $result['tickets'][0]['last_note']);
        $this->assertContains(['key' => 'last_note', 'label' => 'Last Note', 'value' => 'Refund sudah clear'], $result['tickets'][0]['sheet_fields']);

        Cache::flush();
        $filtered = $service->data(['category' => 'API Error']);
        $this->assertSame(2, $filtered['kpis']['total']);
        $this->assertSame(0, $filtered['kpis']['failed']);

        Cache::flush();
        $searched = $service->data(['q' => 'follow up bank']);
        $this->assertSame(1, $searched['kpis']['total']);
        $this->assertSame(1, $searched['kpis']['failed']);
    }

    public function test_it_returns_a_graceful_error_state_when_the_sheets_api_fails(): void
    {
        Cache::flush();
        $this->configureCredentials();

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $result = app(TelegramBotMonitoringService::class)->data([]);

        $this->assertNotNull($result['error']);
        $this->assertSame(0, $result['kpis']['total']);
        $this->assertSame([], $result['tickets']);
    }
}
