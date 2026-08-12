<?php

namespace App\Services;

use App\Models\TopupRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ChecklistService
{
    public function markProcessed(TopupRequest $request, User $user, AuditLogService $audit): TopupRequest
    {
        return DB::transaction(function () use ($request, $user, $audit) {
            $locked = $this->lockedRequest($request);

            if ($locked->status !== 'success') {
                abort(422, 'Hanya transaksi success yang bisa dicentang.');
            }

            if ($locked->is_processed) {
                return $locked;
            }

            $before = $locked->only(['is_processed', 'processed_by_user_id', 'checked_by_email', 'checked_by_role', 'processed_at']);
            $locked->forceFill([
                'is_processed' => true,
                'processed_by_user_id' => $user->id,
                'checked_by_email' => $user->email,
                'checked_by_role' => $user->role ?? 'cs',
                'processed_at' => now(),
            ])->save();

            $updated = $locked->refresh();
            $audit->record('topup.checklist_marked', $updated, $before, $updated->only(array_keys($before)));

            return $updated;
        });
    }

    public function unmarkProcessed(TopupRequest $request, AuditLogService $audit): TopupRequest
    {
        abort(422, 'Checklist yang sudah dicatat tidak bisa dibatalkan.');
    }

    private function lockedRequest(TopupRequest $request): TopupRequest
    {
        $query = TopupRequest::query()->whereKey($request->getKey());

        if ($request->getConnection()->getDriverName() !== 'sqlite') {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }
}
