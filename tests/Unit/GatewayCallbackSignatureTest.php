<?php

namespace Tests\Unit;

use App\Services\Gateway\GatewayCallbackSignatureService;
use Illuminate\Http\Request;
use Tests\TestCase;

class GatewayCallbackSignatureTest extends TestCase
{
    public function test_hilogate_callback_accepts_path_signature(): void
    {
        $secret = 'callback-secret';
        config()->set('paygrid.gateway.hilogate.secret_key', $secret);
        $raw = '{"id":"callback-1","status":"SUCCESS"}';
        $request = Request::create('/api/callbacks/hilogate/transaction', 'POST', [], [], [], [], $raw);
        $request->headers->set('X-Signature', md5('/api/callbacks/hilogate/transaction'.$raw.$secret));

        $this->assertTrue(app(GatewayCallbackSignatureService::class)->verify($request, 'hilogate', [], null));
    }

    public function test_hilogate_callback_rejects_invalid_signature_when_secret_is_configured(): void
    {
        config()->set('paygrid.gateway.hilogate.secret_key', 'callback-secret');
        $request = Request::create('/api/callbacks/hilogate/transaction', 'POST', [], [], [], [], '{}');
        $request->headers->set('X-Signature', 'invalid');

        $this->assertFalse(app(GatewayCallbackSignatureService::class)->verify($request, 'hilogate', [], null));
    }

    public function test_hilogate_callback_is_accepted_from_a_trusted_ip_even_with_an_invalid_signature(): void
    {
        config()->set('paygrid.gateway.hilogate.secret_key', 'callback-secret');
        config()->set('paygrid.security.callback_trusted_ips', ['16.78.139.64']);
        $request = Request::create('/api/callbacks/hilogate/transaction', 'POST', [], [], [], ['REMOTE_ADDR' => '16.78.139.64'], '{}');
        $request->headers->set('X-Signature', 'invalid');

        $this->assertTrue(app(GatewayCallbackSignatureService::class)->verify($request, 'hilogate', [], null));
    }

    public function test_hilogate_callback_from_an_untrusted_ip_still_requires_a_valid_signature(): void
    {
        config()->set('paygrid.gateway.hilogate.secret_key', 'callback-secret');
        config()->set('paygrid.security.callback_trusted_ips', ['16.78.139.64']);
        $request = Request::create('/api/callbacks/hilogate/transaction', 'POST', [], [], [], ['REMOTE_ADDR' => '9.9.9.9'], '{}');
        $request->headers->set('X-Signature', 'invalid');

        $this->assertFalse(app(GatewayCallbackSignatureService::class)->verify($request, 'hilogate', [], null));
    }
}
