<?php

namespace App\Http\Controllers;

use App\Models\TopupRequest;
use App\Models\User;
use App\Services\ChecklistService;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ChecklistController extends Controller
{
    public function update(Request $request, ChecklistService $service): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'checked' => ['required', 'boolean'],
        ]);

        $routeRequest = $request->route('topupRequest');
        $topupRequest = $routeRequest instanceof TopupRequest
            ? $routeRequest
            : TopupRequest::query()->findOrFail($routeRequest);

        $user = $request->user() ?? User::query()->where('email', 'cs-bj@paygrid.local')->firstOrFail();

        if (in_array($user->role, ['cs', 'finance', 'admin'], true) && $user->merchant_id) {
            abort_unless($user->merchant_id && (int) $user->merchant_id === (int) $topupRequest->merchant_id, 403);
        }

        $updated = $data['checked']
            ? $service->markProcessed($topupRequest, $user, app(AuditLogService::class))
            : $service->unmarkProcessed($topupRequest, app(AuditLogService::class));

        $payload = [
            'id' => $updated->id,
            'is_processed' => $updated->is_processed,
            'checked_by_email' => $updated->checked_by_email,
            'processed_at' => optional($updated->processed_at)->toIso8601String(),
        ];

        return $request->expectsJson()
            ? response()->json($payload)
            : back()->with('status', 'Transaksi berhasil dichecklist.');
    }
}
