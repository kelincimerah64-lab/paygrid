<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditLogService
{
    public function record(string $action, Model $target, ?array $before = null, ?array $after = null): AuditLog
    {
        $user = auth()->user();
        $request = app()->bound('request') ? request() : null;

        return AuditLog::query()->create([
            'actor_user_id' => $user?->id,
            'actor_role' => $user?->role,
            'action' => $action,
            'target_type' => $target::class,
            'target_id' => (string) $target->getKey(),
            'before_payload' => $before,
            'after_payload' => $after,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'created_at' => now(),
        ]);
    }
}
