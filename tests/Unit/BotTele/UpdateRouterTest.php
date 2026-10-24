<?php

namespace Tests\Unit\BotTele;

use App\Services\BotTele\UpdateNormalizer;
use App\Services\BotTele\UpdateRouter;
use PHPUnit\Framework\TestCase;

class UpdateRouterTest extends TestCase
{
    private const ADMIN = '-1004445566939';

    private function route(string $text, string $chatId = '-100777', string $chatType = 'supergroup'): ?string
    {
        return (new UpdateRouter)->messageRoute(['text' => $text, 'chat_id' => $chatId, 'chat_type' => $chatType], self::ADMIN);
    }

    public function test_message_routes(): void
    {
        $this->assertSame('support', $this->route('/support'));
        $this->assertSame('support', $this->route('/Support@MerchantSupportBot'));
        $this->assertSame('default', $this->route('/support', self::ADMIN));
        $this->assertSame('default', $this->route('/support please'));

        $this->assertSame('checkticket', $this->route('/checkticket', self::ADMIN));
        $this->assertSame('checkticket', $this->route('/checkticket@MerchantSupportBot', self::ADMIN));
        $this->assertSame('default', $this->route('/checkticket'));

        $this->assertSame('absen', $this->route(' /Absen ', '555', 'private'));
        $this->assertSame('absen', $this->route('absen', '555', 'private'));
        $this->assertSame('default', $this->route('absen'));

        $this->assertSame('default', $this->route('apa saja'));
        $this->assertNull($this->route(''));
    }

    public function test_callback_routes_and_ticket_ids(): void
    {
        $router = new UpdateRouter;

        $this->assertSame(['route' => 'ticket_dashboard', 'ticket_id' => ''], $router->callbackRoute('ticket_dashboard'));
        $this->assertSame(['route' => 'ticket_create_user', 'ticket_id' => ''], $router->callbackRoute('ticket_create_user'));
        $this->assertSame(['route' => 'take', 'ticket_id' => 'HGT-1'], $router->callbackRoute('take_HGT-1'));
        $this->assertSame(['route' => 'progress', 'ticket_id' => 'HGT-1'], $router->callbackRoute('progress_HGT-1'));
        $this->assertSame(['route' => 'update', 'ticket_id' => 'HGT-1'], $router->callbackRoute('update_HGT-1'));
        $this->assertSame(['route' => 'resolve', 'ticket_id' => 'HGT-1'], $router->callbackRoute('resolve_HGT-1'));
        $this->assertSame(['route' => 'failed', 'ticket_id' => 'HGT-1'], $router->callbackRoute('failed_HGT-1'));
        $this->assertSame(['route' => 'picprogress', 'ticket_id' => 'HGT-1'], $router->callbackRoute('PICprogress_HGT-1'));
        $this->assertSame(['route' => 'ipapprove', 'ticket_id' => 'HGT-1'], $router->callbackRoute('ipapprove_HGT-1'));
        $this->assertSame(['route' => 'ipreject', 'ticket_id' => 'HGT-1'], $router->callbackRoute('ipreject_HGT-1'));
        $this->assertNull($router->callbackRoute('ticket_dashboard_extra'));
        $this->assertNull($router->callbackRoute('something_else'));
    }

    public function test_normalizer_uses_photo_caption_as_text(): void
    {
        $u = (new UpdateNormalizer)->normalize(['message' => [
            'message_id' => 9,
            'chat' => ['id' => -100777, 'type' => 'supergroup', 'title' => 'Kingspay'],
            'from' => ['id' => 42, 'first_name' => 'Rama', 'username' => 'rama'],
            'photo' => [['file_id' => 'small', 'width' => 90], ['file_id' => 'large', 'width' => 1280]],
            'caption' => 'Error saat login',
        ]]);

        $this->assertSame('message', $u['event_type']);
        $this->assertTrue($u['has_photo']);
        $this->assertSame('large', $u['photo_file_id']);
        $this->assertSame('Error saat login', $u['text']);
        $this->assertSame(-100777, $u['chat_id']);
        $this->assertSame('', $u['last_name']);
    }

    public function test_normalizer_reads_callback_from_and_message_chat(): void
    {
        $u = (new UpdateNormalizer)->normalize(['callback_query' => [
            'id' => 'cb1',
            'data' => 'take_HGT-1',
            'from' => ['id' => 7, 'first_name' => 'Tech'],
            'message' => ['message_id' => 55, 'chat' => ['id' => -1004445566939, 'type' => 'supergroup', 'title' => 'Tech']],
        ]]);

        $this->assertSame('callback', $u['event_type']);
        $this->assertSame('take_HGT-1', $u['callback_data']);
        $this->assertSame(7, $u['user_id']);
        $this->assertSame(55, $u['message_id']);
        $this->assertSame(-1004445566939, $u['chat_id']);
    }
}
