<?php

namespace Tests\Feature;

use App\Models\JiraTicketSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class JiraSyncTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Throwaway RSA key generated solely for this test suite - see
     * TelegramBotMonitoringServiceTest for the same key/rationale.
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

    private function configure(): void
    {
        config()->set('paygrid.telegram_bot_monitoring.spreadsheet_id', 'sheet-123');
        config()->set('paygrid.telegram_bot_monitoring.sheet_range', 'A:X');
        config()->set('paygrid.telegram_bot_monitoring.service_account_email', 'bot@test.iam.gserviceaccount.com');
        config()->set('paygrid.telegram_bot_monitoring.service_account_private_key', $this->fakePrivateKeyPem());

        config()->set('paygrid.jira_sync.base_url', 'https://inacash-team.test');
        config()->set('paygrid.jira_sync.email', 'bot@inacash.test');
        config()->set('paygrid.jira_sync.api_token', 'fake-jira-token');
        config()->set('paygrid.jira_sync.project_key', 'TIC');
        config()->set('paygrid.jira_sync.issue_type', 'Task');
        config()->set('paygrid.jira_sync.webhook_token', 'sync-secret');
    }

    private function fakeSheet(array $rows): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]),
            'https://sheets.googleapis.com/v4/spreadsheets/sheet-123/values/A:X' => Http::response(['values' => $rows]),
            'https://inacash-team.test/rest/api/3/issue' => Http::response(['key' => 'TIC-100']),
            'https://inacash-team.test/rest/api/3/issue/*/transitions' => function ($request) {
                if ($request->method() === 'GET') {
                    return Http::response(['transitions' => [
                        ['id' => '11', 'to' => ['name' => 'To Do']],
                        ['id' => '21', 'to' => ['name' => 'In Progress']],
                        ['id' => '31', 'to' => ['name' => 'Done']],
                    ]]);
                }

                return Http::response([], 204);
            },
        ]);
    }

    private function header(): array
    {
        return ['ticket_id', 'created_at', 'requester_name', 'category', 'description', 'status', 'assigned_name'];
    }

    public function test_jira_sync_trigger_requires_a_valid_bearer_token(): void
    {
        Cache::flush();
        $this->configure();

        $this->postJson('/api/jira-sync/trigger')->assertStatus(401);
        $this->withHeader('Authorization', 'Bearer wrong')->postJson('/api/jira-sync/trigger')->assertStatus(401);
    }

    public function test_open_tickets_are_skipped_entirely(): void
    {
        Cache::flush();
        $this->configure();
        $this->fakeSheet([
            $this->header(),
            ['T-1', '2026-10-01 10:00:00', 'Budi', 'API Error', 'desc', 'OPEN', ''],
        ]);

        $this->withHeader('Authorization', 'Bearer sync-secret')->postJson('/api/jira-sync/trigger')
            ->assertOk()->assertJson(['created' => 0, 'transitioned' => 0, 'skipped' => 1]);

        $this->assertDatabaseCount('jira_ticket_syncs', 0);
        Http::assertNotSent(fn ($request) => $request->url() === 'https://inacash-team.test/rest/api/3/issue');
    }

    public function test_an_assigned_ticket_creates_a_jira_issue_in_to_do(): void
    {
        Cache::flush();
        $this->configure();
        $this->fakeSheet([
            $this->header(),
            ['T-2', '2026-10-01 10:00:00', 'Budi', 'API Error', 'QRIS gagal', 'ASSIGNED', 'Tech Ina - Gummy'],
        ]);

        $this->withHeader('Authorization', 'Bearer sync-secret')->postJson('/api/jira-sync/trigger')
            ->assertOk()->assertJson(['created' => 1, 'skipped' => 0]);

        $sync = JiraTicketSync::query()->where('ticket_id', 'T-2')->firstOrFail();
        $this->assertSame('TIC-100', $sync->jira_issue_key);
        $this->assertSame('To Do', $sync->last_synced_status);

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://inacash-team.test/rest/api/3/issue') {
                return false;
            }

            return $request['fields']['summary'] === '[T-2] API Error'
                && str_contains(json_encode($request['fields']['description']), 'Tech Ina - Gummy');
        });
    }

    public function test_an_already_synced_ticket_that_moves_to_on_progress_transitions_once(): void
    {
        Cache::flush();
        $this->configure();
        JiraTicketSync::query()->create(['ticket_id' => 'T-3', 'jira_issue_key' => 'TIC-50', 'last_synced_status' => 'To Do']);
        $this->fakeSheet([
            $this->header(),
            ['T-3', '2026-10-01 10:00:00', 'Budi', 'API Error', 'desc', 'ON_PROGRESS', 'Tech Ina - Karim'],
        ]);

        $this->withHeader('Authorization', 'Bearer sync-secret')->postJson('/api/jira-sync/trigger')
            ->assertOk()->assertJson(['created' => 0, 'transitioned' => 1]);

        $this->assertSame('In Progress', JiraTicketSync::query()->where('ticket_id', 'T-3')->value('last_synced_status'));
        Http::assertSentCount(4); // oauth + sheets + 1 GET transitions + 1 POST transitions
    }

    public function test_a_brand_new_resolved_ticket_walks_two_hops_to_done(): void
    {
        Cache::flush();
        $this->configure();
        $this->fakeSheet([
            $this->header(),
            ['T-4', '2026-10-01 10:00:00', 'Budi', 'API Error', 'desc', 'RESOLVED', 'Tech Ina - Karim'],
        ]);

        $this->withHeader('Authorization', 'Bearer sync-secret')->postJson('/api/jira-sync/trigger')
            ->assertOk()->assertJson(['created' => 1]);

        $this->assertSame('Done', JiraTicketSync::query()->where('ticket_id', 'T-4')->value('last_synced_status'));
    }

    public function test_an_already_done_ticket_is_not_re_synced_on_the_next_run(): void
    {
        Cache::flush();
        $this->configure();
        JiraTicketSync::query()->create(['ticket_id' => 'T-5', 'jira_issue_key' => 'TIC-77', 'last_synced_status' => 'Done']);
        $this->fakeSheet([
            $this->header(),
            ['T-5', '2026-10-01 10:00:00', 'Budi', 'API Error', 'desc', 'RESOLVED', 'Tech Ina - Karim'],
        ]);

        $this->withHeader('Authorization', 'Bearer sync-secret')->postJson('/api/jira-sync/trigger')
            ->assertOk()->assertJson(['created' => 0, 'transitioned' => 0]);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/transitions') && $request->method() === 'POST');
    }

    public function test_limit_caps_how_many_tickets_are_touched_in_one_run(): void
    {
        Cache::flush();
        $this->configure();
        $this->fakeSheet([
            $this->header(),
            ['T-6', '2026-10-01 10:00:00', 'Budi', 'API Error', 'desc', 'ASSIGNED', 'Agent A'],
            ['T-7', '2026-10-01 10:00:00', 'Sari', 'API Error', 'desc', 'ASSIGNED', 'Agent B'],
            ['T-8', '2026-10-01 10:00:00', 'Dedi', 'API Error', 'desc', 'ASSIGNED', 'Agent C'],
        ]);

        $this->withHeader('Authorization', 'Bearer sync-secret')->postJson('/api/jira-sync/trigger?limit=2')
            ->assertOk()->assertJson(['created' => 2]);

        $this->assertDatabaseCount('jira_ticket_syncs', 2);
    }
}
