<?php

namespace Tests\Feature;

use App\Models\BotTicket;
use App\Models\Merchant;
use App\Models\MerchantTicket;
use App\Models\User;
use App\Services\BotTele\IpWhitelistBridge;
use App\Services\BotTele\WahaClient;
use App\Services\MerchantTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\UsesBotTeleDatabase;
use Tests\TestCase;

class BotTeleWahaApprovalTest extends TestCase
{
    use RefreshDatabase;
    use UsesBotTeleDatabase;

    private const POLL_ID = 'true_120363431696853359@g.us_3EB06CA52DF3C94799D747_278005146509550@lid';

    private const HMAC_KEY = 'waha-hmac-key';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'bottele.waha.base_url' => 'http://waha.test',
            'bottele.waha.api_key' => 'waha-key',
            'bottele.waha.session' => 'default',
            'bottele.waha.ip_approval_group_id' => '120363431696853359@g.us',
            'bottele.waha.webhook_hmac_key' => self::HMAC_KEY,
        ]);

        Http::fake([
            'http://waha.test/api/sendPoll' => Http::response(['id' => self::POLL_ID]),
            'http://waha.test/api/contacts*' => Http::response(['id' => '6281@c.us', 'pushname' => 'Budi Approver']),
            'http://waha.test/*' => Http::response(['ok' => true]),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 9001]]),
            // Never let a test reach a real server (e.g. PAYGRID_WHATSAPP_API_URL from a local .env).
            '*' => Http::response(['ok' => true]),
        ]);
    }

    private function sentTo(string $urlEnd): Collection
    {
        return collect(Http::recorded())
            ->filter(fn (array $pair): bool => str_contains($pair[0]->url(), $urlEnd))
            ->map(fn (array $pair): Request => $pair[0])
            ->values();
    }

    private function webTicket(): MerchantTicket
    {
        $this->seed();
        $merchant = Merchant::query()->whereNotNull('agent_id')->firstOrFail();

        return app(MerchantTicketService::class)->create($merchant, User::factory()->create(['name' => 'Rina Toko']), [
            'department' => 'tech',
            'category' => 'ip_whitelist',
            'title' => 'Whitelist',
            'description' => 'Tolong whitelist 1.2.3.4',
        ]);
    }

    private function vote(array $payload, ?string $key = self::HMAC_KEY)
    {
        $body = json_encode($payload);
        $headers = ['CONTENT_TYPE' => 'application/json'];
        if ($key !== null) {
            $headers['HTTP_X_WEBHOOK_HMAC'] = hash_hmac('sha512', $body, $key);
        }

        return $this->call('POST', '/api/bottele/webhooks/waha-incoming', [], [], [], $headers, $body);
    }

    private function pollVote(string $option, string $pollId = self::POLL_ID): array
    {
        return [
            'event' => 'poll.vote',
            'session' => 'default',
            'payload' => [
                'vote' => ['id' => 'vote1', 'selectedOptions' => [$option], 'from' => '6281@c.us', 'timestamp' => 1792718742],
                'poll' => ['id' => $pollId, 'to' => '120363431696853359@g.us', 'from' => '6280@c.us', 'fromMe' => true],
            ],
        ];
    }

    public function test_web_ip_whitelist_ticket_sends_poll_and_creates_linked_bot_ticket(): void
    {
        $ticket = $this->webTicket()->refresh();

        $botTicket = BotTicket::findByTicketId((string) $ticket->bot_ticket_id);
        $this->assertNotNull($botTicket);
        $this->assertMatchesRegularExpression('/^HGT-\d{8}-\d{18}$/', $botTicket->ticket_id);
        $this->assertSame('IP Whitelist / VPN', $botTicket->category);
        $this->assertSame('WAITING_APPROVAL', $botTicket->status);
        $this->assertSame('WAITING_APPROVAL', $botTicket->approval_status);
        $this->assertSame(self::POLL_ID, $botTicket->approval_group_message_id);
        $this->assertSame('Rina Toko', $botTicket->requester_name);
        $this->assertSame($ticket->merchant->agent->name, $botTicket->source_chat_title);

        $text = $this->sentTo('/api/sendText')->sole();
        $this->assertSame('waha-key', $text->header('X-Api-Key')[0]);
        $this->assertSame('120363431696853359@g.us', $text['chatId']);
        $this->assertStringStartsWith("*Approval IP Whitelist*\n".$ticket->ticket_no."\n", $text['text']);
        $this->assertStringContainsString("Reported By : Rina Toko\nMerchant Name : ".$ticket->merchant->name."\nKeterangan : Tolong whitelist 1.2.3.4", $text['text']);

        $poll = $this->sentTo('/api/sendPoll')->sole();
        $this->assertSame('Approve tiket '.$ticket->ticket_no.'?', $poll['poll']['name']);
        $this->assertSame(['Approve', 'Reject'], $poll['poll']['options']);
        $this->assertFalse($poll['poll']['multipleAnswers']);
    }

    public function test_webhook_rejects_missing_or_wrong_signature(): void
    {
        $this->vote($this->pollVote('Approve'), null)->assertForbidden();
        $this->vote($this->pollVote('Approve'), 'wrong-key')->assertForbidden();

        config(['bottele.waha.webhook_hmac_key' => '']);
        $this->vote($this->pollVote('Approve'), '')->assertForbidden();
    }

    public function test_approve_vote_opens_bot_ticket_approves_web_ticket_and_pushes_take_card(): void
    {
        $ticket = $this->webTicket()->refresh();

        $this->vote($this->pollVote('Approve'))->assertOk()->assertJson(['outcome' => 'approved']);

        $botTicket = BotTicket::findByTicketId($ticket->bot_ticket_id);
        $this->assertSame('OPEN', $botTicket->status);
        $this->assertSame('APPROVED', $botTicket->approval_status);
        $this->assertSame('Budi Approver', $botTicket->approval_by_name);
        $this->assertNotNull($botTicket->approval_completed_at);
        $this->assertSame(9001, (int) $botTicket->cs_group_message_id);

        $ticket->refresh();
        $this->assertSame('approved', $ticket->approval_status);
        $this->assertSame('Budi Approver', $ticket->approval_by);
        $this->assertDatabaseHas('merchant_ticket_messages', ['merchant_ticket_id' => $ticket->id, 'body' => 'Permintaan IP Whitelist disetujui via WhatsApp oleh Budi Approver.']);

        $contact = $this->sentTo('/api/contacts')->sole();
        $this->assertStringContainsString('contactId=6281%40c.us', $contact->url());

        $push = $this->sentTo('api.telegram.org')->sole();
        $this->assertSame('-1004445566939', $push['chat_id']);
        $this->assertStringContainsString("Reported By : Rina Toko\nIssue : Tolong whitelist 1.2.3.4\nStatus : 🔴\nApproval : Approved\nApproved By : Budi Approver", $push['text']);
        $this->assertSame('take_'.$botTicket->ticket_id, $push['reply_markup']['inline_keyboard'][0][0]['callback_data']);

        // A second vote on a decided poll changes nothing.
        $this->vote($this->pollVote('Reject'))->assertOk()->assertJson(['outcome' => 'already_decided']);
        $this->assertSame('OPEN', $botTicket->refresh()->status);
    }

    public function test_reject_vote_rejects_both_tickets(): void
    {
        $ticket = $this->webTicket()->refresh();

        $this->vote($this->pollVote('Reject'))->assertOk()->assertJson(['outcome' => 'rejected']);

        $botTicket = BotTicket::findByTicketId($ticket->bot_ticket_id);
        $this->assertSame('REJECTED', $botTicket->status);
        $this->assertSame('REJECTED', $botTicket->approval_status);
        $ticket->refresh();
        $this->assertSame('rejected', $ticket->approval_status);
        $this->assertSame('closed', $ticket->status);
        $this->assertCount(0, $this->sentTo('api.telegram.org'));
    }

    public function test_dashboard_decision_first_syncs_bot_ticket_and_blocks_the_poll(): void
    {
        $ticket = $this->webTicket()->refresh();
        $ticket->forceFill(['approval_status' => 'approved', 'approval_by' => 'Approver Dashboard', 'approval_completed_at' => now()])->save();

        app(IpWhitelistBridge::class)->syncFromDashboard($ticket);

        $botTicket = BotTicket::findByTicketId($ticket->bot_ticket_id);
        $this->assertSame('OPEN', $botTicket->status);
        $this->assertSame('Approver Dashboard', $botTicket->approval_by_name);
        $this->assertCount(1, $this->sentTo('api.telegram.org'));

        $this->vote($this->pollVote('Reject'))->assertOk()->assertJson(['outcome' => 'already_decided']);
        $this->assertSame('approved', $ticket->refresh()->approval_status);
    }

    public function test_other_events_unknown_polls_and_odd_payloads_never_fail(): void
    {
        $this->webTicket();
        Log::spy();

        $this->vote(['event' => 'message', 'payload' => ['body' => 'halo']])->assertOk()->assertJson(['outcome' => 'ignored']);
        $this->vote($this->pollVote('Approve', 'true_other_poll'))->assertOk()->assertJson(['outcome' => 'no_ticket']);
        $this->vote(['event' => 'poll.vote', 'payload' => ['something' => 'else']])->assertOk()->assertJson(['outcome' => 'unrecognized']);
        $this->vote($this->pollVote('Maybe'))->assertOk()->assertJson(['outcome' => 'unrecognized']);

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'bottele.waha_unrecognized_poll_vote' && isset($context['payload']))->twice();
    }

    public function test_ticket_creation_skips_the_poll_until_waha_is_configured(): void
    {
        config(['bottele.waha.ip_approval_group_id' => '']);

        $ticket = $this->webTicket()->refresh();

        $this->assertNull($ticket->bot_ticket_id);
        $this->assertSame(0, BotTicket::query()->count());
        $this->assertCount(0, $this->sentTo('waha.test'));
    }

    public function test_poll_id_extraction_tries_known_response_shapes(): void
    {
        $this->assertSame('a', WahaClient::pollId(['id' => ['_serialized' => 'a', 'id' => 'b']]));
        $this->assertSame('b', WahaClient::pollId(['id' => ['id' => 'b']]));
        $this->assertSame('c', WahaClient::pollId(['id' => 'c']));
        $this->assertSame('d', WahaClient::pollId(['_data' => ['id' => ['_serialized' => 'd']]]));
        $this->assertSame('', WahaClient::pollId(null));
        $this->assertSame('', WahaClient::pollId(['something' => 'else']));
    }
}
