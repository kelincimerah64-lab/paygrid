<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MerchantScopeMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $merchant = $request->route('merchant');

        if ($user && $merchant && in_array($user->role, ['cs', 'finance', 'admin', 'readonly_admin', 'readonly_cs'], true) && $user->merchant_id) {
            abort_unless($user->merchant_id && (int) $user->merchant_id === (int) $merchant->id, 403);
        }

        return $next($request);
    }
}
