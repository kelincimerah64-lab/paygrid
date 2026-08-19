<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReadonlyUserMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->role && str_starts_with($request->user()->role, 'readonly_') && ! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            abort(403, 'Akun readonly hanya bisa melihat data.');
        }

        return $next($request);
    }
}
