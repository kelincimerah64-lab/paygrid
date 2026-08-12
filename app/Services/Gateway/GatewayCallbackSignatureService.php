<?php

namespace App\Services\Gateway;

use App\Models\Merchant;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class GatewayCallbackSignatureService
{
    public function verify(Request $request, string $gateway, array $payload, ?Merchant $merchant = null): bool
    {
        if ($gateway !== 'hilogate') {
            return true;
        }

        $trustedIps = config('paygrid.security.callback_trusted_ips', []);
        if ($trustedIps && ! in_array($request->ip(), $trustedIps, true)) {
            return false;
        }

        $secret = (string) ($merchant?->merchant_key ?: config('paygrid.gateway.hilogate.secret_key'));
        if ($secret === '') {
            return true;
        }

        $incoming = (string) ($request->header('X-Signature')
            ?: $request->header('X-Merchant-Signature')
            ?: $request->header('Merchant-Signature')
            ?: $request->header('Signature')
            ?: Arr::get($payload, 'merchant_signature')
            ?: Arr::get($payload, 'signature'));

        if ($incoming === '') {
            return false;
        }

        $rawBody = $request->getContent();
        $path = '/'.ltrim($request->path(), '/');
        $incoming = strtolower(trim(str_replace(['"', "'"], '', $incoming)));

        return hash_equals(md5($path.$rawBody.$secret), $incoming)
            || hash_equals(md5($rawBody.$secret), $incoming);
    }
}
