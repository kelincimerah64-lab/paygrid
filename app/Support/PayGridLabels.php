<?php

namespace App\Support;

class PayGridLabels
{
    public static function status(string|null $status): string
    {
        return match ((string) $status) {
            'success' => 'Sukses',
            'pending' => 'Pending',
            'expired' => 'Expired',
            'failed' => 'Gagal',
            'rejected' => 'Ditolak',
            'not_started' => 'Belum Dimulai',
            'open' => 'Open',
            'in_progress' => 'Diproses',
            'done' => 'Selesai',
            'cancelled' => 'Dibatalkan',
            'checking' => 'Checking',
            'issue_bank' => 'Issue Bank',
            'issue_switching' => 'Issue Switching',
            'approved' => 'Approved',
            'pending_agent' => 'Pending Agen',
            'pending_ma' => 'Pending MA',
            default => str($status ?: '-')->replace('_', ' ')->title()->toString(),
        };
    }

    public static function badge(string|null $status): string
    {
        return in_array($status, ['success', 'done', 'approved', 'active', 'Active'], true)
            ? 'ok'
            : (in_array($status, ['expired', 'failed', 'rejected', 'cancelled', 'Suspended'], true) ? 'danger' : 'warn');
    }

    public static function centerStatusBadge(string|null $status): string
    {
        return $status === 'success'
            ? 'ok'
            : (str_starts_with((string) $status, 'issue') ? 'danger' : 'warn');
    }
}
