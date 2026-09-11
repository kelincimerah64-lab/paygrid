<?php

namespace App\Http\Middleware;

use App\Services\CsScopeResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MerchantScopeMiddleware
{
    public function __construct(private CsScopeResolver $csScope)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $merchant = $request->route('merchant');

        if ($user && $merchant && in_array($user->role, ['cs', 'finance', 'admin', 'readonly_admin', 'readonly_cs'], true) && $user->merchant_id) {
            abort_unless($user->merchant_id && (int) $user->merchant_id === (int) $merchant->id, 403);
        }

        if ($user && $merchant && in_array($user->role, ['cs_ma', 'cs_agent', 'agent', 'ma'], true)) {
            abort_unless($this->csScope->canAccessMerchant($user, $merchant), 403);
        }

        return $next($request);
    }
}
