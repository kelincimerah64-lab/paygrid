<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeadersMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');

        // Symfony's ResponseHeaderBag always injects a "no-cache, private" Cache-Control
        // by default, so has('Cache-Control') is never a reliable "was it set?" check.
        // Only overwrite that silent default; leave a controller's deliberate value alone.
        if (in_array($response->headers->get('Cache-Control'), [null, 'no-cache, private'], true)) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
            $response->headers->set('Pragma', 'no-cache');
        }
        // The realtime dashboards (ma-wa-tickets-show, cs-monitor) open a WebSocket
        // straight to Reverb, which lives on a different host than the app itself
        // (the mobile VPS) - connect-src has to explicitly allow that origin or the
        // browser silently drops the connection with no visible error on the page,
        // only a CSP violation in devtools.
        $connectSrc = "'self'";
        $reverbHost = config('broadcasting.connections.reverb.options.host');
        if ($reverbHost) {
            $reverbPort = config('broadcasting.connections.reverb.options.port');
            $reverbScheme = config('broadcasting.connections.reverb.options.scheme') === 'https' ? 'wss' : 'ws';
            $connectSrc .= " {$reverbScheme}://{$reverbHost}:{$reverbPort}";
        }

        $csp = "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: blob:; connect-src {$connectSrc}; frame-ancestors 'none'; base-uri 'self'; form-action 'self'";

        if ($request->isSecure()) {
            $csp .= '; upgrade-insecure-requests';
        }

        $response->headers->set('Content-Security-Policy', $csp);

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
