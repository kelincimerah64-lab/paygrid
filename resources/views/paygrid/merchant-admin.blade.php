@extends('layouts.paygrid')

@php
    $money = fn ($value) => number_format((int) ($value ?? 0), 0, ',', '.');
    $statusClass = fn ($status) => $status === 'success' ? 'ok' : (in_array($status, ['expired', 'failed', 'rejected'], true) ? 'danger' : 'warn');
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
<div class="qris-hero">
    <div>
        <div class="eyebrow">{{ strtoupper($merchant->merchant_type) }} Admin</div>
        <h1>{{ $title }}</h1>
    </div>
</div>

@if(session('status'))
    <section class="card pad section"><span class="badge ok">{{ session('status') }}</span></section>
@endif

@if($active === 'users')
    <section class="card pad section">
        <h2>Create Role User</h2>
        <form method="post" action="{{ route('merchant.admin.users.store', $merchant) }}" class="admin-create-form section">
            @csrf
            <label>Email<input name="email" type="email" required placeholder="email@domain.com"></label>
            <label>Role<select name="role" required><option value="cs">CS</option><option value="finance">Finance</option><option value="admin">Admin</option></select></label>
            <label>Password<input name="password" required minlength="6" placeholder="Password awal"></label>
            <button class="btn primary compact-btn">Create User</button>
        </form>
    </section>

    <section class="card qris-panel section">
        <div class="qris-toolbar"><strong>Data User</strong><div class="muted">CS, finance, dan admin toko ini.</div></div>
        <div class="table-wrap">
            <table class="table qris-table admin-user-table compact-user-table">
                <thead><tr><th>Nama</th><th>Email</th><th>Role</th><th>Password</th><th>Reset Password</th></tr></thead>
                <tbody>
                @foreach($users as $user)
                    <tr>
                        <td><strong>{{ $user->name }}</strong></td>
                        <td>{{ $user->email }}</td>
                        <td><strong>{{ strtoupper($user->role) }}</strong></td>
                        <td><strong>{{ $user->plain_password ?: 'Hash only' }}</strong></td>
                        <td>
                            <form method="post" action="{{ route('merchant.admin.users.reset-password', [$merchant, $user]) }}" class="actions compact-actions reset-inline">
                                @csrf
                                <input name="password" minlength="6" required placeholder="Password baru">
                                <button class="btn primary compact-btn">Reset</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>
@elseif($active === 'settings')
    <section class="card pad section admin-minimum-card">
        <h2>Atur Minimum Topup QRIS</h2>
        <form method="post" action="{{ route('merchant.admin.minimum-topup.update', $merchant) }}" class="admin-minimum-form section">
            @csrf
            <label>Minimum Topup<input name="minimum_topup_amount" type="number" min="1000" max="{{ config('paygrid.topup.maximum_amount') }}" value="{{ old('minimum_topup_amount', $merchant->minimumTopupAmount()) }}" required></label>
            <button class="btn primary compact-btn">Simpan</button>
        </form>
    </section>
@elseif($active === 'logs')
    <section class="card qris-panel section">
        <div class="qris-toolbar"><strong>Log Aktivitas</strong><div class="muted">Hanya menampilkan aktivitas checklist transaksi.</div></div>
        <div class="table-wrap">
            <table class="table qris-table admin-log-table">
                <thead><tr><th>Waktu</th><th>User</th><th>Aktivitas</th><th>Data</th><th>Keterangan</th></tr></thead>
                <tbody>
                @forelse($logs as $log)
                    @php($targetTopup = $log->target_type === App\Models\TopupRequest::class ? ($topupLogTargets[(int) $log->target_id] ?? null) : null)
                    <tr>
                        <td class="time-cell">{{ $log->created_at?->timezone('Asia/Jakarta')->format('d/m/Y') }}<span>{{ $log->created_at?->timezone('Asia/Jakarta')->format('H.i.s') }}</span></td>
                        <td><strong>{{ $log->actor?->email ?: '-' }}</strong><br><span class="muted">{{ strtoupper($log->actor_role ?: '-') }}</span></td>
                        <td>{{ $actionLabel($log->action) }}</td>
                        <td>{{ $targetTopup ? 'Transaksi '.($targetTopup->transaction_id ?: $targetTopup->payment_id ?: $targetTopup->id) : class_basename($log->target_type).' '.$log->target_id }}</td>
                        <td class="muted">{{ $log->action === 'topup.checklist_marked' ? 'Transaksi dicentang selesai oleh user toko.' : json_encode($log->after_payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="empty">Belum ada log aktivitas.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
@elseif(in_array($active, ['qris', 'checklist', 'history'], true))
    <section class="card qris-panel section">
        <form class="qris-toolbar" method="get">
            <div><strong>{{ $active === 'qris' ? 'Topup Request' : ($active === 'history' ? 'History TRX' : 'Sukses Checklist') }}</strong><div class="muted">Data transaksi merchant ini.</div></div>
            <div class="qris-filters">
                <input type="date" name="from" value="{{ $from }}">
                <input type="date" name="to" value="{{ $to }}">
                <input class="search" name="q" value="{{ $search }}" placeholder="Cari trx, RRN, payment...">
                <button class="btn primary">Filter</button>
            </div>
        </form>
        <div class="table-wrap">
            <table class="table qris-table admin-trx-table">
                <thead><tr><th>Waktu</th><th>Reference</th><th>RRN</th><th>Amount</th><th>Status</th>@if($active !== 'history')<th>Checklist</th>@endif</tr></thead>
                <tbody>
                @forelse($requests as $request)
                    <tr>
                        <td class="time-cell">{{ $request->submitted_at?->timezone('Asia/Jakarta')->format('d M y') ?? '-' }}<span>{{ $request->submitted_at?->timezone('Asia/Jakarta')->format('H:i:s') ?? '-' }}</span></td>
                        <td><strong class="truncate ref-line">{{ $request->payment_id ?: '-' }}</strong><span class="muted truncate ref-line">TRX: {{ $request->transaction_id ?: '-' }}</span></td>
                        <td>{{ $request->rrn ?: '-' }}</td>
                        <td>Rp {{ $money($request->amount) }}</td>
                        <td><span class="badge {{ $statusClass($request->status) }}">{{ ucfirst($request->status) }}</span></td>
                        @if($active !== 'history')<td>
                            @if($request->status === 'success')
                                @if($request->is_processed)
                                    <span class="check-icon checked" title="Checked" aria-label="Checked"></span><br><span class="muted">{{ $request->checked_by_email ?: '-' }}</span>
                                @else
                                    <form method="post" action="{{ route('api.checklist.update', $request) }}" class="compact-actions">@csrf @method('PATCH')<input type="hidden" name="checked" value="1"><button class="check-icon" type="submit" title="Checklist" aria-label="Checklist"></button></form>
                                @endif
                            @else
                                -
                            @endif
                        </td>@endif
                    </tr>
                @empty
                    <tr><td colspan="{{ $active === 'history' ? 5 : 6 }}" class="empty">Belum ada transaksi.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="qris-pagination pad">
            <div class="pager-summary">Showing {{ $requests->firstItem() ?? 0 }} to {{ $requests->lastItem() ?? 0 }}</div>
            <div class="pager-links">
                @if($requests->onFirstPage())<span class="pager disabled">Prev</span>@else<a class="pager" href="{{ $requests->previousPageUrl() }}">Prev</a>@endif
                @if($requests->hasMorePages())<a class="pager" href="{{ $requests->nextPageUrl() }}">Next</a>@else<span class="pager disabled">Next</span>@endif
            </div>
        </div>
    </section>
@endif
@endsection
