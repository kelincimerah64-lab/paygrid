@extends((request()->boolean('partial') || request()->header('X-PayGrid-Partial') === '1') ? 'layouts.partial' : 'layouts.paygrid')

@php
    $money = fn ($value) => number_format((int) ($value ?? 0), 0, ',', '.');
    $statusClass = fn ($status) => App\Support\PayGridLabels::badge($status);
    $statusLabel = fn ($status) => App\Support\PayGridLabels::status($status);
    $canManageUsers = in_array(request()->user()?->role, ['admin', 'ma', 'superadmin'], true);
    $canUncheckChecklist = $canManageUsers;
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
    @if($canManageUsers)
    <section class="card pad section admin-create-card">
        <h2>Create Role User</h2>
        <p class="muted">Tambahkan akun CS, Finance, atau Admin khusus untuk toko ini.</p>
        <form method="post" action="{{ route('merchant.admin.users.store', $merchant) }}" class="admin-create-form section">
            @csrf
            <label>Email<input name="email" type="email" required placeholder="email@domain.com"></label>
            <label>Role<select name="role" required><option value="cs">CS</option><option value="finance">Finance</option><option value="admin">Admin</option></select></label>
            <label>Password<input name="password" required minlength="6" placeholder="Password awal"></label>
            <button class="btn primary">Create User</button>
        </form>
    </section>
    @endif

    <section class="card qris-panel section">
        <div class="qris-toolbar"><strong>Data User</strong><div class="muted">CS, finance, dan admin toko ini.</div></div>
        <div class="table-wrap">
            <table class="table qris-table admin-user-table compact-user-table">
                <thead><tr><th>Nama</th><th>Email</th><th>Role</th>@if($canManageUsers)<th>Password</th><th>Reset Password</th>@endif</tr></thead>
                <tbody>
                @foreach($users as $user)
                    <tr>
                        <td><strong>{{ $user->name }}</strong></td>
                        <td>{{ $user->email }}</td>
                        <td><strong>{{ strtoupper($user->role) }}</strong></td>
                        @if($canManageUsers)
                        <td><strong>{{ $user->plain_password ?: 'Reset diperlukan' }}</strong></td>
                        <td>
                            <form method="post" action="{{ route('merchant.admin.users.reset-password', [$merchant, $user]) }}" class="actions compact-actions reset-inline">
                                @csrf
                                <input name="password" minlength="6" required placeholder="Password baru">
                                <button class="btn primary compact-btn">Reset</button>
                            </form>
                        </td>
                        @endif
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
            <label>Minimum Topup<input name="minimum_topup_amount" type="number" min="1000" value="{{ old('minimum_topup_amount', $merchant->minimumTopupAmount()) }}" required></label>
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
                    @php
                        $targetTopup = $log->target_type === App\Models\TopupRequest::class
                            ? ($topupLogTargets[(int) $log->target_id] ?? null)
                            : null;
                    @endphp
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
    @php
        $dashboardTitle = $active === 'qris' ? 'Top Up Request' : ($active === 'checklist' ? 'Success Checklist' : 'History TRX');
        $dashboardSubtitle = $active === 'qris'
            ? 'Kelola transaksi pending, sukses, expired, dan status checklist toko ini.'
            : ($active === 'checklist' ? 'Review transaksi sukses dan tandai checklist yang sudah diselesaikan admin/CS.' : 'Riwayat transaksi toko berdasarkan data lokal PayGrid.');
        $routeName = $active === 'qris' ? 'merchant.admin.qris' : ($active === 'checklist' ? 'merchant.admin.checklist' : 'merchant.admin.history');
        $filterBase = array_filter(['from' => $from, 'to' => $to, 'q' => $search], fn ($value) => $value !== null && $value !== '');
        $latestSyncLabel = $latestSync?->finished_at?->timezone('Asia/Jakarta')->format('H.i.s') ?? 'Belum ada sync';
        $dashboardRefreshLabel = now('Asia/Jakarta')->format('H:i:s');
    @endphp
    @if($active === 'history')
        <section class="merchant-workspace-head section">
            <div>
                <p>{{ $dashboardSubtitle }}</p>
                <span>Terakhir sync gateway: {{ $latestSyncLabel }} <span class="muted">| Refresh dashboard: {{ $dashboardRefreshLabel }}</span></span>
            </div>
            <form method="get" class="merchant-workspace-filter" data-auto-filter data-auto-filter-delay="450">
                <input class="search" name="q" value="{{ $search }}" placeholder="Cari username, ID, RRN...">
                <input type="hidden" name="status" value="{{ $status !== 'all' ? $status : '' }}">
                <label><span>Dari</span><input type="date" name="from" value="{{ $from }}"></label>
                <label><span>Sampai</span><input type="date" name="to" value="{{ $to }}"></label>
                <button class="btn primary">Submit Filter</button>
            </form>
        </section>

        <section class="grid qris-metrics history-metrics">
            <div class="card pad qris-metric primary"><span>Request Sukses</span><strong>{{ number_format($stats['total'], 0, ',', '.') }}</strong><small>{{ $from ?: 'All' }} - {{ $to ?: 'All' }}</small></div>
            <div class="card pad qris-metric success"><span>Success Volume</span><strong>Rp {{ number_format($stats['volume_success'], 0, ',', '.') }}</strong><small>{{ number_format($stats['success'], 0, ',', '.') }} sukses</small></div>
            <div class="card pad qris-metric pending"><span>Pending</span><strong>{{ number_format($stats['pending'], 0, ',', '.') }}</strong><small>Unpaid</small></div>
            <div class="card pad qris-metric expired"><span>Expired / Failed</span><strong>{{ number_format($stats['expired'], 0, ',', '.') }}</strong><small>Need follow up</small></div>
            <div class="card pad qris-metric success"><span>Available Balance HG</span><strong>{{ number_format((int) ($gatewayBalance['active'] ?? 0), 0, ',', '.') }}</strong><small>Merchant {{ $merchant->name }}</small></div>
        </section>

        <section class="card qris-panel section">
            <div class="qris-toolbar"><h2>Daftar Transaksi</h2><div class="muted">History transaksi tanpa checklist.</div></div>
            <div class="table-wrap sticky-head">
                <table class="table qris-table history-table">
                    <thead><tr><th>Masuk</th><th>Sukses</th><th>Durasi</th><th>Reference</th><th>RRN</th><th>TRX ID</th><th>Amount</th><th>Status</th><th>Keterangan</th><th>Tindak Lanjut</th></tr></thead>
                    <tbody>
                    @forelse($requests as $request)
                        <tr>
                            <td class="time-mini">{{ $request->submitted_at?->format('H.i.s') ?? '-' }}</td>
                            <td class="time-mini">{{ $request->succeeded_at?->format('H.i.s') ?? '-' }}</td>
                            <td class="duration-cell">{{ $request->successDurationLabel() }}</td>
                            <td><span class="truncate ref-line">{{ $request->customer_reference ?: $request->payment_id ?: $request->gateway_ref_id ?: '-' }}</span></td>
                            <td><strong>{{ $request->rrn ?: '-' }}</strong></td>
                            <td><button class="btn trx-view" type="button" data-trx="{{ $request->transaction_id ?: $request->idempotency_key ?: '-' }}">Lihat</button></td>
                            <td><strong>{{ number_format((int) $request->amount, 0, ',', '.') }}</strong></td>
                            <td><span class="badge {{ $statusClass($request->status) }}">{{ $statusLabel($request->status) }}</span></td>
                            <td>{{ $request->cs_note ?: '-' }}</td>
                            <td><span class="muted">-</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="empty"><strong>Belum ada transaksi.</strong>Coba ubah periode/filter atau tunggu sync Hilogate berikutnya.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <div class="qris-pagination pad"><div class="pager-summary">Showing {{ $requests->firstItem() ?? 0 }} to {{ $requests->lastItem() ?? 0 }}</div><div class="pager-links">@if($requests->onFirstPage())<span class="pager disabled">Prev</span>@else<a class="pager" href="{{ $requests->previousPageUrl() }}">Prev</a>@endif @if($requests->hasMorePages())<a class="pager" href="{{ $requests->nextPageUrl() }}">Next</a>@else<span class="pager disabled">Next</span>@endif</div></div>
        </section>
    @else
    <div data-live-root data-live-interval="3000">
    <section class="merchant-workspace-head section" data-live-region="admin-workspace-head">
        <div>
            <p>{{ $dashboardSubtitle }}</p>
            <span>Terakhir sync gateway: {{ $latestSyncLabel }} <span class="muted">| Refresh dashboard: {{ $dashboardRefreshLabel }}</span></span>
        </div>
        <form method="get" class="merchant-workspace-filter" data-auto-filter data-auto-filter-delay="450">
            <input class="search" name="q" value="{{ $search }}" placeholder="Cari username, ID, RRN...">
            <label><span>Dari</span><input type="date" name="from" value="{{ $from }}"></label>
            <label><span>Sampai</span><input type="date" name="to" value="{{ $to }}"></label>
            @if($active === 'checklist')<input type="hidden" name="processed" value="{{ $processed !== 'all' ? $processed : '' }}">@endif
            @if($active !== 'checklist')<input type="hidden" name="status" value="{{ $status !== 'all' ? $status : '' }}"><input type="hidden" name="processed" value="{{ $processed !== 'all' ? $processed : '' }}">@endif
            <button class="btn primary">Submit Filter</button>
        </form>
    </section>

    @if($active === 'qris')
        <section class="grid topup-cards merchant-workspace-cards" data-live-region="admin-cards">
            <a class="card pad topup-card {{ $status === 'pending' ? 'active-card' : '' }}" href="{{ route($routeName, [$merchant] + $filterBase + ['status' => 'pending']) }}"><span>Transaksi Pending</span><div class="topup-card-row"><small>Jumlah</small><strong>{{ number_format($topupCards['pending_count'], 0, ',', '.') }}</strong></div><div class="topup-card-row"><small>Nilai</small><strong>{{ number_format($topupCards['pending_amount'], 0, ',', '.') }}</strong></div><small>Klik untuk lihat pending</small></a>
            <a class="card pad topup-card {{ $status === 'success' && $processed === 'unchecked' ? 'active-card' : '' }}" href="{{ route($routeName, [$merchant] + $filterBase + ['status' => 'success', 'processed' => 'unchecked']) }}"><span>Success Belum Checklist</span><div class="topup-card-row"><small>Jumlah</small><strong>{{ number_format($topupCards['success_unchecked_count'], 0, ',', '.') }}</strong></div><div class="topup-card-row"><small>Nilai</small><strong>{{ number_format($topupCards['success_unchecked_amount'], 0, ',', '.') }}</strong></div><small>Prioritas paling atas</small></a>
            <a class="card pad topup-card {{ $status === 'success' && $processed === 'checked' ? 'active-card' : '' }}" href="{{ route($routeName, [$merchant] + $filterBase + ['status' => 'success', 'processed' => 'checked']) }}"><span>Sukses Checklist</span><div class="topup-card-row"><small>Jumlah</small><strong>{{ number_format($topupCards['success_checked_count'], 0, ',', '.') }}</strong></div><div class="topup-card-row"><small>Nilai</small><strong>{{ number_format($topupCards['success_checked_amount'], 0, ',', '.') }}</strong></div><small>Sudah dicentang admin/CS</small></a>
            <a class="card pad topup-card {{ $status === 'expired' ? 'active-card' : '' }}" href="{{ route($routeName, [$merchant] + $filterBase + ['status' => 'expired']) }}"><span>Expired</span><div class="topup-card-row"><small>Jumlah</small><strong>{{ number_format($topupCards['expired_count'], 0, ',', '.') }}</strong></div><div class="topup-card-row"><small>Nilai</small><strong>{{ number_format($topupCards['expired_amount'], 0, ',', '.') }}</strong></div><small>Expired / failed / rejected</small></a>
            <div class="card pad topup-card balance-card"><span>Available Balance HG</span><div class="balance-number">{{ number_format((int) ($gatewayBalance['active'] ?? 0), 0, ',', '.') }}</div><small>Saldo aktif dari Hilogate</small></div>
            <div class="card pad topup-card pending-balance-card"><span>Saldo Pending HG</span><div class="balance-number">{{ number_format((int) ($gatewayBalance['pending'] ?? 0), 0, ',', '.') }}</div><small>Saldo pending dari Hilogate</small></div>
        </section>
        <section class="workspace-tabs"><a class="btn {{ $status === 'all' && $processed === 'all' ? 'primary' : '' }}" href="{{ route($routeName, [$merchant] + $filterBase) }}">Semua</a><a class="btn {{ $status === 'success' && $processed === 'all' ? 'primary' : '' }}" href="{{ route($routeName, [$merchant] + $filterBase + ['status' => 'success']) }}">Sukses saja</a><a class="btn {{ $status === 'success' && $processed === 'checked' ? 'primary' : '' }}" href="{{ route($routeName, [$merchant] + $filterBase + ['status' => 'success', 'processed' => 'checked']) }}">Sukses checklist</a><a class="btn {{ $status === 'pending' ? 'primary' : '' }}" href="{{ route($routeName, [$merchant] + $filterBase + ['status' => 'pending']) }}">Pending</a><a class="btn {{ $status === 'expired' ? 'primary' : '' }}" href="{{ route($routeName, [$merchant] + $filterBase + ['status' => 'expired']) }}">Expired</a></section>
    @elseif($active === 'checklist')
        <section class="grid checklist-cards merchant-workspace-cards" data-live-region="admin-cards">
            <a class="card pad topup-card {{ $processed === 'all' ? 'active-card' : '' }}" href="{{ route($routeName, [$merchant] + $filterBase) }}"><span>Total Topup Sukses</span><div class="topup-card-row"><small>Jumlah</small><strong>{{ number_format($stats['success'], 0, ',', '.') }}</strong></div><div class="topup-card-row"><small>Nilai</small><strong>{{ number_format($stats['volume_success'], 0, ',', '.') }}</strong></div><small>Check dan belum check</small></a>
            <a class="card pad topup-card {{ $processed === 'checked' ? 'active-card' : '' }}" href="{{ route($routeName, [$merchant] + $filterBase + ['processed' => 'checked']) }}"><span>Sukses Sudah Checklist</span><div class="topup-card-row"><small>Jumlah</small><strong>{{ number_format($topupCards['success_checked_count'], 0, ',', '.') }}</strong></div><div class="topup-card-row"><small>Nilai</small><strong>{{ number_format($topupCards['success_checked_amount'], 0, ',', '.') }}</strong></div><small>Sudah dicentang admin/CS</small></a>
            <a class="card pad topup-card {{ $processed === 'unchecked' ? 'active-card' : '' }}" href="{{ route($routeName, [$merchant] + $filterBase + ['processed' => 'unchecked']) }}"><span>Sukses Belum Checklist</span><div class="topup-card-row"><small>Jumlah</small><strong>{{ number_format($topupCards['success_unchecked_count'], 0, ',', '.') }}</strong></div><div class="topup-card-row"><small>Nilai</small><strong>{{ number_format($topupCards['success_unchecked_amount'], 0, ',', '.') }}</strong></div><small>Prioritas dicek</small></a>
            <div class="card pad topup-card balance-card"><span>Available Balance HG</span><div class="balance-number">{{ number_format((int) ($gatewayBalance['active'] ?? 0), 0, ',', '.') }}</div><small>Saldo aktif dari Hilogate</small></div>
            <div class="card pad topup-card pending-balance-card"><span>Saldo Pending HG</span><div class="balance-number">{{ number_format((int) ($gatewayBalance['pending'] ?? 0), 0, ',', '.') }}</div><small>Saldo pending dari Hilogate</small></div>
        </section>
        <section class="workspace-tabs"><a class="btn {{ $processed === 'all' ? 'primary' : '' }}" href="{{ route($routeName, [$merchant] + $filterBase) }}">Semua sukses</a><a class="btn {{ $processed === 'unchecked' ? 'primary' : '' }}" href="{{ route($routeName, [$merchant] + $filterBase + ['processed' => 'unchecked']) }}">Belum checklist</a><a class="btn {{ $processed === 'checked' ? 'primary' : '' }}" href="{{ route($routeName, [$merchant] + $filterBase + ['processed' => 'checked']) }}">Sudah checklist</a></section>
    @endif

    <section class="card qris-panel section" data-live-region="admin-workspace-table">
        <div class="table-wrap sticky-head">
            <table class="table workspace-table {{ $active === 'qris' ? 'topup-table' : 'checklist-table' }}">
                <thead><tr>@if($active === 'qris')<th>Nama Toko</th><th>Masuk</th><th>Sukses</th><th>Durasi</th><th>Nominal</th><th>Status</th><th>Checked by</th><th>Checkbox</th><th>ID / RRN</th><th>Action</th><th>Keterangan</th>@else<th>Merchant</th><th>Masuk</th><th>Sukses</th><th>Durasi</th><th>Nominal</th><th>Checked by</th><th>Checkbox</th><th>ID / RRN</th><th>Submit</th><th>Keterangan</th>@endif</tr></thead>
                <tbody>
                @forelse($requests as $request)
                    <tr class="{{ $request->status === 'success' && $request->is_processed ? ($active === 'checklist' ? 'checked-row' : 'topup-success-checked-row') : '' }}">
                        <td><span class="muted">{{ $merchant->name }}</span><br><strong>{{ $request->customer_reference ?: $request->transaction_id ?: $request->payment_id ?: '-' }}</strong></td>
                        <td class="time-mini">{{ $request->submitted_at?->format('H.i.s') ?? '-' }}</td>
                        <td class="time-mini">{{ $request->succeeded_at?->format('H.i.s') ?? '-' }}</td>
                        <td class="duration-cell">{{ $request->successDurationLabel() }}</td>
                        <td><strong>{{ number_format($request->amount, 0, ',', '.') }}</strong></td>
                        @if($active === 'qris')<td><span class="badge {{ $statusClass($request->status) }}">{{ $statusLabel($request->status) }}</span></td>@endif
                        <td class="workspace-checked-by">{{ $request->checked_by_email ?: '-' }}@if($request->checked_by_role)<br><span class="muted">{{ strtoupper($request->checked_by_role) }}</span>@endif</td>
                        <td class="checkbox-cell">@if($request->status === 'success' && $request->is_processed)@if($canUncheckChecklist)<form method="post" action="{{ route('api.checklist.update', $request) }}" class="compact-actions">@csrf @method('PATCH')<input type="hidden" name="checked" value="0"><button class="check-icon checked" type="submit" title="Lepas checklist" aria-label="Lepas checklist" onclick="return confirm('Lepas checklist transaksi ini?')"></button></form>@else<span class="check-icon checked"></span>@endif @elseif($request->status === 'success')<form method="post" action="{{ route('api.checklist.update', $request) }}" class="compact-actions">@csrf @method('PATCH')<input type="hidden" name="checked" value="1"><button class="check-icon" type="submit" title="Checklist" aria-label="Checklist"></button></form>@else<span class="check-icon"></span>@endif</td>
                        <td><div class="id-stack"><code>{{ str($request->payment_id ?: $request->gateway_ref_id ?: '-')->limit(18) }}</code><span class="muted">RRN: {{ str($request->rrn ?: '-')->limit(14) }}</span></div></td>
                        <td>@if($active === 'qris' && $request->status === 'pending' && ! $request->ticket)<form method="post" action="{{ route('merchant.cs.topup.ticket', [$merchant, $request]) }}">@csrf<button class="btn">Tunggu {{ $request->submitted_at?->diffInMinutes(now()) ?? 0 }} menit</button></form>@elseif($request->ticket)<span class="badge ok">Sudah ticket</span><br><span class="muted">{{ $request->ticket->ticket_no }}</span>@elseif($active === 'checklist' && $request->status === 'success' && ! $request->is_processed)<form method="post" action="{{ route('api.checklist.update', $request) }}">@csrf @method('PATCH')<input type="hidden" name="checked" value="1"><button class="btn primary">Check</button></form>@else<span class="muted">-</span>@endif</td>
                        <td><textarea name="cs_note_{{ $request->id }}" @unless($request->is_processed) data-cs-note data-note-url="{{ route('api.topup-requests.cs-note', $request) }}" @endunless data-preserve-key="cs-note-{{ $request->id }}" placeholder="{{ $request->is_processed ? '' : 'Tulis keterangan...' }}" @readonly($request->is_processed)>{{ $request->cs_note }}</textarea></td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $active === 'qris' ? 11 : 10 }}" class="empty"><strong>Belum ada transaksi.</strong>Coba ubah periode/filter atau tunggu sync Hilogate berikutnya.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="qris-pagination pad"><div class="pager-summary">Showing {{ $requests->firstItem() ?? 0 }} to {{ $requests->lastItem() ?? 0 }}</div><div class="pager-links">@if($requests->onFirstPage())<span class="pager disabled">Prev</span>@else<a class="pager" href="{{ $requests->previousPageUrl() }}">Prev</a>@endif @if($requests->hasMorePages())<a class="pager" href="{{ $requests->nextPageUrl() }}">Next</a>@else<span class="pager disabled">Next</span>@endif</div></div>
    </section>
    </div>
    @endif
@endif
@endsection
