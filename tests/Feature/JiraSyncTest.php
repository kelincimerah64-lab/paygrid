<?php

namespace Tests\Feature;

use App\Models\JiraTicketSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\UsesBotTeleDatabase;
use Tests\TestCase;

class JiraSyncTest extends TestCase
{
    use RefreshDatabase;
    use UsesBotTeleDatabase;

    private function configure(): void
    {
        config()->set('paygrid.jira_sync.base_url', 'https://inacash-team.test');
        config()->set('paygrid.jira_sync.email', 'bot@inacash.test');
        config()->set('paygrid.jira_sync.api_token', 'fake-jira-token');
        config()->set('paygrid.jira_sync.project_key', 'TIC');
        config()->set('paygrid.jira_sync.issue_type', 'Task');
        config()->set('paygrid.jira_sync.webhook_token', 'sync-secret');
    }

    private function fakeSheet(array $rows): void
    {
        $this->seedBotTickets($rows);

        Http::fake([
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

            // descriptionFor() always inserts a blank line before "Deskripsi:",
            // which previously left a gap in the array keys array_filter()
            // produced - PHP's json_encode then serialized that as a JSON
            // *object* instead of an array, which Jira's ADF parser rejects.
            // Decoding the RAW body (not the in-memory PHP array Http::fake
            // hands back, which hides the gap) is what actually catches that.
            $decoded = json_decode($request->body());
            $content = $decoded->fields->description->content;

            return $request['fields']['summary'] === '[T-2] API Error'
                && is_array($content)
                && str_contains(json_encode($content), 'Tech Ina - Gummy');
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
        Http::assertSentCount(2); // 1 GET transitions + 1 POST transitions
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
