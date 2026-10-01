<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxyTest extends TestCase
{
    private function registerIpEchoRoute(): void
    {
        Route::get('/__test-ip-echo', fn () => request()->ip());
    }

    public function test_x_forwarded_for_is_ignored_when_the_connecting_peer_is_not_a_trusted_cloudflare_ip(): void
    {
        $this->registerIpEchoRoute();

        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '1.2.3.4',
            'HTTP_X_FORWARDED_FOR' => '16.78.139.64',
        ])->get('/__test-ip-echo');

        $response->assertOk();
        $this->assertSame('1.2.3.4', $response->getContent());
    }

    public function test_x_forwarded_for_is_honored_when_the_connecting_peer_is_a_trusted_cloudflare_ip(): void
    {
        $this->registerIpEchoRoute();

        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '104.16.1.1',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
        ])->get('/__test-ip-echo');

        $response->assertOk();
        $this->assertSame('203.0.113.9', $response->getContent());
    }

    public function test_x_forwarded_for_is_honored_when_the_connecting_peer_is_the_main_vps_proxying_to_the_mobile_vps(): void
    {
        $this->registerIpEchoRoute();

        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '15.232.137.74',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
        ])->get('/__test-ip-echo');

        $response->assertOk();
        $this->assertSame('203.0.113.9', $response->getContent());
    }
}
