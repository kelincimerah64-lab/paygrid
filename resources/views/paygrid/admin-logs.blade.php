@extends('layouts.paygrid', ['roleLabel' => 'Monitoring Center', 'menus' => app(App\Services\Navigation\MenuBuilder::class)->admin(), 'active' => 'logs'])

@php
    $actionLabel = fn ($action) => match ($action) {
        'topup.checklist_marked' => 'Ubah status transaksi',
        'admin.user_created' => 'Buat user',
        'admin.user_password_reset' => 'Reset password user',
        'admin.minimum_topup_updated' => 'Ubah minimum topup',
        'auth.login_success' => 'Login berhasil',
        'auth.login_failed' => 'Login gagal',
        'auth.logout' => 'Logout',
        default => ucfirst(str_replace(['.', '_'], ' ', $action)),
    };
@endphp

@section('content')
<div class="page-head"><div><h1>Log Aktivitas</h1><div class="sub">Seluruh aktivitas penting di PayGrid: akun, fee, agen, toko, dan login.</div></div></div>
<section class="card section">
    <form method="get" class="filters">
        <input class="search" name="q" value="{{ request('q') }}" placeholder="Cari aksi, email, target...">
        <select name="action">
            <option value="">Semua aksi</option>
            @foreach($actions as $value)
                <option value="{{ $value }}" @selected(request('action') === $value)>{{ $actionLabel($value) }}</option>
            @endforeach
        </select>
        <input type="date" name="from" value="{{ request('from') }}">
        <input type="date" name="to" value="{{ request('to') }}">
        <button class="btn primary">Filter</button>
        <a class="btn" href="{{ route('admin.logs') }}">Reset</a>
    </form>
    <div class="table-wrap">
        <table class="table qris-table admin-log-table">
            <thead><tr><th>Waktu</th><th>User</th><th>Aktivitas</th><th>Data</th><th>Keterangan</th></tr></thead>
            <tbody>
            @forelse($logs as $log)
                <tr>
                    <td class="time-cell">{{ $log->created_at?->timezone('Asia/Jakarta')->format('d/m/Y') }}<span>{{ $log->created_at?->timezone('Asia/Jakarta')->format('H.i.s') }}</span></td>
                    <td><strong>{{ $log->actor?->email ?: '-' }}</strong><br><span class="muted">{{ strtoupper($log->actor_role ?: '-') }}</span></td>
                    <td>{{ $actionLabel($log->action) }}</td>
                    <td>{{ $log->target_type ? class_basename($log->target_type).' #'.$log->target_id : '-' }}</td>
                    <td class="muted truncate ref-line">{{ $log->after_payload ? json_encode($log->after_payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '-' }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty">Belum ada log aktivitas.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="qris-pagination pad">
        <div class="pager-summary">Showing {{ $logs->firstItem() ?? 0 }} to {{ $logs->lastItem() ?? 0 }}</div>
        <div class="pager-links">
            @if($logs->onFirstPage())<span class="pager disabled">Prev</span>@else<a class="pager" href="{{ $logs->previousPageUrl() }}">Prev</a>@endif
            @if($logs->hasMorePages())<a class="pager" href="{{ $logs->nextPageUrl() }}">Next</a>@else<span class="pager disabled">Next</span>@endif
        </div>
    </div>
</section>
@endsection
