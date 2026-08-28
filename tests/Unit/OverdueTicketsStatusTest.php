<?php

namespace Tests\Unit;

use App\Services\TelegramBotMonitoringService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OverdueTicketsStatusTest extends TestCase
{
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

    public function test_overdue_tickets_excludes_resolved_and_failed_by_status_not_timestamp(): void
    {
        Cache::flush();
        config()->set('paygrid.telegram_bot_monitoring.spreadsheet_id', 'sheet-123');
        config()->set('paygrid.telegram_bot_monitoring.sheet_range', 'A:X');
        config()->set('paygrid.telegram_bot_monitoring.service_account_email', 'bot@test.iam.gserviceaccount.com');
        config()->set('paygrid.telegram_bot_monitoring.service_account_private_key', $this->fakePrivateKeyPem());
        config()->set('paygrid.telegram_bot_monitoring.reminder_threshold_minutes', 15);

        $old = now()->subMinutes(30)->format('Y-m-d H:i:s');

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]),
            'https://sheets.googleapis.com/v4/spreadsheets/sheet-123/values/A:X' => Http::response([
                'values' => [
                    // Note: this sheet schema has no completed_at/updated_at column at all, mirroring production.
                    ['ticket_id', 'created_at', 'requester_name', 'status', 'handling_minutes'],
                    ['T-RESOLVED', $old, 'Budi', 'RESOLVED', '-25163'],
                    ['T-FAILED', $old, 'Sari', 'FAILED', '100'],
                    ['T-OPEN', $old, 'Dedi', 'OPEN', '50'],
                    ['T-ASSIGNED', $old, 'Rina', 'ASSIGNED', '10'],
                ],
            ]),
        ]);

        $overdue = app(TelegramBotMonitoringService::class)->overdueTickets();

        $this->assertSame(['T-OPEN', 'T-ASSIGNED'], $overdue->pluck('ticket_id')->all());
    }
}
