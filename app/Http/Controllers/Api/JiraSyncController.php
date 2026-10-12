<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\JiraTicketSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JiraSyncController extends Controller
{
    public function trigger(Request $request, JiraTicketSyncService $sync): JsonResponse
    {
        $token = (string) config('paygrid.jira_sync.webhook_token');
        abort_unless($token !== '' && hash_equals($token, (string) $request->bearerToken()), 401);

        $result = $sync->sync($request->filled('limit') ? $request->integer('limit') : null);

        return response()->json(['ok' => true] + $result);
    }
}
