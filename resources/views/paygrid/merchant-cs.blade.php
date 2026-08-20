@extends((request()->boolean('partial') || request()->header('X-PayGrid-Partial') === '1') ? 'layouts.partial' : 'layouts.paygrid')

@php
    $title = $active === 'history' ? 'History Transaksi' : ($active === 'topup' ? 'Topup Request' : ($active === 'checklist' ? 'Sukses Checklist' : 'Tiket Status'));
    $money = fn ($value) => 'Rp '.number_format((int) ($value ?? 0), 0, ',', '.');
    $statusClass = fn ($status) => App\Support\PayGridLabels::badge($status);
    $statusLabel = fn ($status) => App\Support\PayGridLabels::status($status);
    $latestSyncAt = $latestSync?->finished_at?->timezone('Asia/Jakarta')->format('d M Y, H:i:s') ?? 'Belum ada sync';
    $dashboardRefreshAt = now('Asia/Jakarta')->format('H:i:s');
    $paginator = $active === 'tickets' ? $tickets : $requests;
    $panelTitle = match ($active) {
        'tickets' => 'Daftar Tiket',
        'checklist' => 'Sukses Checklist',
        default => 'Daftar Transaksi',
    };
    $filterBase = ['period' => $period, 'from' => $from, 'to' => $to];
    $isCardActive = fn (?string $status, ?string $processed = null) => request('status') === $status && (string) request('processed') === (string) $processed;
    $ticketPendingMinutes = (int) App\Models\PaygridSetting::value('ticket_pending_minutes', '40');
    $ticketDeadlineFor = function ($row) use ($ticketPendingMinutes) {
        $base = $row->submitted_at && $row->submitted_at->lte(now()->addMinute()) ? $row->submitted_at : $row->created_at;
        if ($base) {
            return $base->copy()->addMinutes($ticketPendingMinutes);
        }

        return $row->expires_at ? $row->expires_at->copy()->addMinutes(max(0, $ticketPendingMinutes - config('paygrid.topup.expires_in_minutes', 30))) : null;
    };
    $isWorkspace = in_array($active, ['topup', 'checklist'], true);
    $workspaceRoute = $active === 'checklist' ? 'merchant.cs.checklist' : 'merchant.cs.topup';
    $workspaceSubtitle = $active === 'checklist'
        ? 'Review transaksi sukses dan tandai checklist yang sudah diselesaikan CS.'
        : 'Kelola transaksi pending, sukses, expired, dan status checklist toko ini.';
    $canUncheckChecklist = in_array(request()->user()?->role, ['admin', 'ma', 'superadmin'], true);
@endphp

@section('content')
@if(! $isWorkspace)
    <section class="qris-hero">
        <div>
            <h1>{{ $title }}</h1>
        </div>
    </section>
@endif

@if(session('status'))
    <div class="card pad section" style="margin-top:0; margin-bottom:12px; background:#ecfff5; border-color:#a4ebc4; color:#008450">{{ session('status') }}</div>
@endif
@if($errors->any())
    <div class="card pad section" style="margin-top:0; margin-bottom:12px; background:#fff1f0; border-color:#f0b4ae; color:#c62828">{{ $errors->first() }}</div>
@endif

@if($isWorkspace)
    <div data-live-root data-live-interval="3000">
    <section class="merchant-workspace-head section" data-live-region="cs-workspace-head">
        <div>
            <h1>{{ $title }}</h1>
            <a class="workspace-link" href="{{ route('merchant.cs.topup', $merchant) }}">{{ $merchant->name }} CS Dashboard</a>
            <p>{{ $workspaceSubtitle }}</p>
            <span>Terakhir sync gateway: {{ $latestSyncAt }} <span class="muted">| Refresh dashboard: {{ $dashboardRefreshAt }}</span></span>
        </div>
        <form method="get" action="{{ route($workspaceRoute, $merchant) }}" class="merchant-workspace-filter" data-auto-filter data-auto-filter-delay="450">
            <input type="hidden" name="period" value="{{ $period }}">
            <input class="search" name="q" value="{{ request('q') }}" placeholder="Cari username, ID, RRN...">
            <label><span>Dari</span><input type="date" name="from" value="{{ $from }}"></label>
            <label><span>Sampai</span><input type="date" name="to" value="{{ $to }}"></label>
            @if($active === 'checklist')
                <input type="hidden" name="processed" value="{{ request('processed') }}">
            @else
                <input type="hidden" name="status" value="{{ request('status') }}">
                <input type="hidden" name="processed" value="{{ request('processed') }}">
            @endif
            <button class="btn primary">Submit Filter</button>
        </form>
    </section>
@endif

@if($active === 'tickets')
<section class="grid qris-metrics ticket-metrics">
    <div class="card pad qris-metric primary">
        <span>Total Ticket</span>
        <strong>{{ number_format($ticketStats['total'], 0, ',', '.') }}</strong>
        <small>{{ $period === 'all' ? 'All period' : (($from ?: '-') . ' - ' . ($to ?: '-')) }}</small>
    </div>
    <div class="card pad qris-metric pending">
        <span>Open</span>
        <strong>{{ number_format($ticketStats['open'], 0, ',', '.') }}</strong>
        <small>Need submit / follow up</small>
    </div>
    <div class="card pad qris-metric success">
        <span>Done</span>
        <strong>{{ number_format($ticketStats['done'], 0, ',', '.') }}</strong>
        <small>Closed by CS pusat</small>
    </div>
</section>
@elseif($active === 'topup')
<section class="grid topup-cards merchant-workspace-cards" data-live-region="cs-topup-cards">
    <a class="card pad topup-card {{ $isCardActive('pending') ? 'active-card' : '' }}" href="{{ route('merchant.cs.topup', [$merchant] + $filterBase + ['status' => 'pending']) }}">
        <span>Transaksi Pending</span>
        <div class="topup-card-row"><small>Jumlah</small><strong>{{ number_format($topupCards['pending_count'], 0, ',', '.') }}</strong></div>
        <div class="topup-card-row"><small>Nilai</small><strong>{{ number_format($topupCards['pending_amount'], 0, ',', '.') }}</strong></div>
    </a>
    <a class="card pad topup-card {{ $isCardActive('success', 'unchecked') ? 'active-card' : '' }}" href="{{ route('merchant.cs.topup', [$merchant] + $filterBase + ['status' => 'success', 'processed' => 'unchecked']) }}">
        <span>Success Belum Checklist</span>
        <div class="topup-card-row"><small>Jumlah</small><strong>{{ number_format($topupCards['success_unchecked_count'], 0, ',', '.') }}</strong></div>
        <div class="topup-card-row"><small>Nilai</small><strong>{{ number_format($topupCards['success_unchecked_amount'], 0, ',', '.') }}</strong></div>
    </a>
    <a class="card pad topup-card {{ $isCardActive('success', 'checked') ? 'active-card' : '' }}" href="{{ route('merchant.cs.topup', [$merchant] + $filterBase + ['status' => 'success', 'processed' => 'checked']) }}">
        <span>Sukses Checklist</span>
        <div class="topup-card-row"><small>Jumlah</small><strong>{{ number_format($topupCards['success_checked_count'], 0, ',', '.') }}</strong></div>
        <div class="topup-card-row"><small>Nilai</small><strong>{{ number_format($topupCards['success_checked_amount'], 0, ',', '.') }}</strong></div>
    </a>
    <a class="card pad topup-card {{ $isCardActive('expired') ? 'active-card' : '' }}" href="{{ route('merchant.cs.topup', [$merchant] + $filterBase + ['status' => 'expired']) }}">
        <span>Expired</span>
        <div class="topup-card-row"><small>Jumlah</small><strong>{{ number_format($topupCards['expired_count'], 0, ',', '.') }}</strong></div>
        <div class="topup-card-row"><small>Nilai</small><strong>{{ number_format($topupCards['expired_amount'], 0, ',', '.') }}</strong></div>
    </a>
    <div class="card pad topup-card balance-card">
        <span>Available Balance HG</span>
        <div class="balance-number">{{ number_format((int) ($gatewayBalance['active'] ?? 0), 0, ',', '.') }}</div>
    </div>
    <div class="card pad topup-card pending-balance-card">
        <span>Saldo Pending HG</span>
        <div class="balance-number">{{ number_format((int) ($gatewayBalance['pending'] ?? 0), 0, ',', '.') }}</div>
    </div>
</section>
<section class="workspace-tabs"><a class="btn {{ ! request('status') && ! request('processed') ? 'primary' : '' }}" href="{{ route('merchant.cs.topup', [$merchant] + $filterBase) }}">Semua</a><a class="btn {{ request('status') === 'success' && ! request('processed') ? 'primary' : '' }}" href="{{ route('merchant.cs.topup', [$merchant] + $filterBase + ['status' => 'success']) }}">Sukses saja</a><a class="btn {{ $isCardActive('success', 'checked') ? 'primary' : '' }}" href="{{ route('merchant.cs.topup', [$merchant] + $filterBase + ['status' => 'success', 'processed' => 'checked']) }}">Sukses checklist</a><a class="btn {{ request('status') === 'pending' ? 'primary' : '' }}" href="{{ route('merchant.cs.topup', [$merchant] + $filterBase + ['status' => 'pending']) }}">Pending</a><a class="btn {{ request('status') === 'expired' ? 'primary' : '' }}" href="{{ route('merchant.cs.topup', [$merchant] + $filterBase + ['status' => 'expired']) }}">Expired</a></section>
@elseif($active === 'checklist')
<section class="grid checklist-cards merchant-workspace-cards" data-live-region="cs-checklist-cards">
    <a class="card pad topup-card {{ ! request('processed') ? 'active-card' : '' }}" href="{{ route('merchant.cs.checklist', [$merchant] + $filterBase) }}">
        <span>Total Topup Sukses</span>
        <div class="topup-card-row"><small>Jumlah</small><strong>{{ number_format($stats['success'], 0, ',', '.') }}</strong></div>
        <div class="topup-card-row"><small>Nilai</small><strong>{{ number_format($stats['volume_success'], 0, ',', '.') }}</strong></div>
    </a>
    <a class="card pad topup-card {{ request('processed') === 'checked' ? 'active-card' : '' }}" href="{{ route('merchant.cs.checklist', [$merchant] + $filterBase + ['processed' => 'checked']) }}">
        <span>Sukses Sudah Checklist</span>
        <div class="topup-card-row"><small>Jumlah</small><strong>{{ number_format($topupCards['success_checked_count'], 0, ',', '.') }}</strong></div>
        <div class="topup-card-row"><small>Nilai</small><strong>{{ number_format($topupCards['success_checked_amount'], 0, ',', '.') }}</strong></div>
    </a>
    <a class="card pad topup-card {{ request('processed') === 'unchecked' ? 'active-card' : '' }}" href="{{ route('merchant.cs.checklist', [$merchant] + $filterBase + ['processed' => 'unchecked']) }}">
        <span>Sukses Belum Checklist</span>
        <div class="topup-card-row"><small>Jumlah</small><strong>{{ number_format($topupCards['success_unchecked_count'], 0, ',', '.') }}</strong></div>
        <div class="topup-card-row"><small>Nilai</small><strong>{{ number_format($topupCards['success_unchecked_amount'], 0, ',', '.') }}</strong></div>
    </a>
    <div class="card pad topup-card balance-card">
        <span>Available Balance HG</span>
        <div class="balance-number">{{ number_format((int) ($gatewayBalance['active'] ?? 0), 0, ',', '.') }}</div>
    </div>
    <div class="card pad topup-card pending-balance-card">
        <span>Saldo Pending HG</span>
        <div class="balance-number">{{ number_format((int) ($gatewayBalance['pending'] ?? 0), 0, ',', '.') }}</div>
    </div>
</section>
<section class="workspace-tabs"><a class="btn {{ ! request('processed') ? 'primary' : '' }}" href="{{ route('merchant.cs.checklist', [$merchant] + $filterBase) }}">Semua sukses</a><a class="btn {{ request('processed') === 'unchecked' ? 'primary' : '' }}" href="{{ route('merchant.cs.checklist', [$merchant] + $filterBase + ['processed' => 'unchecked']) }}">Belum checklist</a><a class="btn {{ request('processed') === 'checked' ? 'primary' : '' }}" href="{{ route('merchant.cs.checklist', [$merchant] + $filterBase + ['processed' => 'checked']) }}">Sudah checklist</a></section>
@else
<section class="grid qris-metrics {{ $active === 'history' ? 'history-metrics' : '' }}">
    <div class="card pad qris-metric primary">
        <span>Request Sukses</span>
        <strong>{{ number_format($stats['total'], 0, ',', '.') }}</strong>
        <small>{{ $from ?: 'All' }} - {{ $to ?: 'All' }}</small>
    </div>
    <div class="card pad qris-metric success">
        <span>Success Volume</span>
        <strong>{{ $money($stats['volume_success']) }}</strong>
        <small>{{ number_format($stats['success'], 0, ',', '.') }} sukses</small>
    </div>
    <div class="card pad qris-metric pending">
        <span>Pending</span>
        <strong>{{ number_format($stats['pending'], 0, ',', '.') }}</strong>
        <small>Unpaid</small>
    </div>
    <div class="card pad qris-metric expired">
        <span>Expired / Failed</span>
        <strong>{{ number_format($stats['expired'] + $stats['failed'], 0, ',', '.') }}</strong>
        <small>Need follow up</small>
    </div>
    @if($active === 'history')
        <div class="card pad qris-metric success">
        <span>Available Balance HG</span>
            <strong>{{ number_format((int) ($gatewayBalance['active'] ?? 0), 0, ',', '.') }}</strong>
            <small>Merchant {{ $merchant->name }}</small>
        </div>
    @endif
</section>
@endif

<section class="card qris-panel" data-live-region="cs-table">
    <div class="qris-toolbar">
        <h2>{{ $panelTitle }}</h2>
        @if($isWorkspace)
            <div class="muted">Showing data sesuai filter di atas.</div>
        @else
        <form method="get" class="qris-filters">
            <input class="search" name="q" value="{{ request('q') }}" placeholder="Search...">
            <select name="period" data-period-select>
                @if($active !== 'tickets')<option value="today" @selected($period === 'today')>Hari ini</option>@endif
                <option value="this_month" @selected($period === 'this_month')>Bulan ini</option>
                <option value="last_month" @selected($period === 'last_month')>Bulan lalu</option>
                <option value="all" @selected($period === 'all')>{{ $active === 'tickets' ? 'Semua tiket' : 'Semua data' }}</option>
            </select>
            @if($active === 'tickets')
                <select name="status">
                    <option value="all" @selected(request('status', 'all') === 'all')>All status</option>
                    @foreach(['not_started', 'open', 'in_progress', 'done', 'cancelled'] as $status)
                        <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                    @endforeach
                </select>
            @elseif($active !== 'checklist')
                <select name="status">
                    <option value="all" @selected(request('status', 'all') === 'all')>All status</option>
                    @foreach(['success', 'pending', 'expired'] as $status)
                        <option value="{{ $status }}" @selected(request('status') === $status || (request('status') === 'failed' && $status === 'expired'))>{{ ucfirst($status) }}</option>
                    @endforeach
                </select>
                <input type="hidden" name="processed" value="{{ request('processed') }}">
            @endif
            @if($active === 'checklist')
                <input type="hidden" name="processed" value="{{ request('processed') }}">
            @endif
            <input type="date" name="from" value="{{ $period === 'all' ? '' : $from }}" data-date-from>
            <input type="date" name="to" value="{{ $period === 'all' ? '' : $to }}" data-date-to>
            <button class="btn primary">Filter</button>
        </form>
        @endif
    </div>

    <div class="table-wrap">
        @if($active === 'tickets')
        <table class="table qris-table ticket-table">
            <thead>
                <tr>
                    <th>Dibuat</th>
                    <th>Ticket</th>
                    <th>Customer</th>
                    <th>Issue</th>
                    <th>Status Pusat</th>
                    <th>Catatan</th>
                    <th>Submit</th>
                </tr>
            </thead>
            <tbody>
            @forelse($tickets as $ticket)
                @php($topup = $ticket->topupRequest)
                <tr>
                    <td>
                        <strong>{{ $ticket->created_at?->timezone('Asia/Jakarta')->format('H:i:s') ?? '-' }}</strong><br>
                        <span class="muted">{{ $ticket->created_at?->timezone('Asia/Jakarta')->format('d M Y') ?? '-' }}</span>
                    </td>
                    <td>
                        <strong>{{ $ticket->ticket_no }}</strong><br>
                        <span class="muted truncate ref-line">{{ $ticket->reference ?: '-' }}</span>
                    </td>
                    <td><strong class="truncate ref-line">{{ $ticket->client_reference ?: $topup?->customer_reference ?: '-' }}</strong></td>
                    <td><span class="truncate ref-line">{{ $ticket->issue }}</span></td>
                    <td><span class="badge {{ App\Support\PayGridLabels::centerStatusBadge($ticket->center_status) }}">{{ $statusLabel($ticket->center_status ?: $ticket->status) }}</span></td>
                    <td>{{ $ticket->center_note ?: (count($ticket->attachments ?? []) ? count($ticket->attachments).' lampiran' : 'Belum ada lampiran') }}</td>
                    <td>
                        @if($ticket->submitted_to_center_at)
                            <span class="badge ok">Terkirim</span>
                        @elseif(! in_array($ticket->status, ['done', 'cancelled'], true))
                            <form method="post" action="{{ route('merchant.cs.ticket.submit', [$merchant, $ticket]) }}" enctype="multipart/form-data" class="ticket-submit">
                                @csrf
                                <label class="file-pick">
                                    Pilih file
                                    <input type="file" name="attachment" accept="image/*" required>
                                </label>
                                <span class="file-name">Belum pilih</span>
                                <button class="btn primary" type="submit">Submit</button>
                            </form>
                        @else
                            <span class="muted">-</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7"><div class="empty">Belum ada tiket pada filter ini.</div></td></tr>
            @endforelse
            </tbody>
        </table>
        @else
        @if($isWorkspace)
        <table class="table workspace-table {{ $active === 'topup' ? 'topup-table' : 'checklist-table' }}">
            <thead>
                <tr>
                    @if($active === 'topup')
                        <th>Nama Toko</th><th>Masuk</th><th>Sukses</th><th>Durasi</th><th>Nominal</th><th>Status</th><th>Checked by</th><th>Checkbox</th><th>ID / RRN</th><th>Action</th><th>Keterangan</th>
                    @else
                        <th>Merchant</th><th>Masuk</th><th>Sukses</th><th>Durasi</th><th>Nominal</th><th>Checked by</th><th>Checkbox</th><th>ID / RRN</th><th>Submit</th><th>Keterangan</th>
                    @endif
                </tr>
            </thead>
            <tbody>
            @forelse($requests as $row)
                <tr class="{{ $row->status === 'success' && $row->is_processed ? ($active === 'checklist' ? 'checked-row' : 'topup-success-checked-row') : '' }}">
                    <td><span class="muted">{{ $merchant->name }}</span><br><strong>{{ $row->customer_reference ?: $row->transaction_id ?: $row->payment_id ?: '-' }}</strong></td>
                    <td class="time-mini">{{ $row->submitted_at?->format('H.i.s') ?? '-' }}</td>
                    <td class="time-mini">{{ $row->succeeded_at?->format('H.i.s') ?? '-' }}</td>
                    <td class="duration-cell">{{ $row->successDurationLabel() }}</td>
                    <td><strong>{{ number_format((int) $row->amount, 0, ',', '.') }}</strong></td>
                    @if($active === 'topup')
                        <td><span class="badge {{ $statusClass($row->status) }}">{{ $statusLabel($row->status) }}</span></td>
                    @endif
                    <td class="workspace-checked-by">{{ $row->checked_by_email ?: '-' }}@if($row->checked_by_role)<br><span class="muted">{{ strtoupper($row->checked_by_role) }}</span>@endif</td>
                    <td class="checkbox-cell">
                        @if($row->status === 'success' && $row->is_processed)
                            @if($canUncheckChecklist)
                                <form method="post" action="{{ route('api.checklist.update', $row) }}" class="compact-actions">
                                    @csrf @method('PATCH')
                                    <input type="hidden" name="checked" value="0">
                                    <button class="check-icon checked" type="submit" title="Lepas checklist" aria-label="Lepas checklist" onclick="return confirm('Lepas checklist transaksi ini?')"></button>
                                </form>
                            @else
                                <span class="check-icon checked" title="Checked" aria-label="Checked"></span>
                            @endif
                        @elseif($row->status === 'success')
                            <form method="post" action="{{ route('api.checklist.update', $row) }}" class="compact-actions">
                                @csrf @method('PATCH')
                                <input type="hidden" name="checked" value="1">
                                <button class="check-icon" type="submit" title="Checklist" aria-label="Checklist"></button>
                            </form>
                        @else
                            <span class="check-icon"></span>
                        @endif
                    </td>
                    <td><div class="id-stack"><code>{{ str($row->payment_id ?: $row->gateway_ref_id ?: '-')->limit(18) }}</code><span class="muted">RRN: {{ str($row->rrn ?: '-')->limit(14) }}</span></div></td>
                    <td>
                        @if($active === 'topup' && in_array($row->status, ['pending', 'expired', 'failed', 'rejected'], true))
                            @php($ticketDeadline = $ticketDeadlineFor($row))
                            @php($remaining = $ticketDeadline && now()->lt($ticketDeadline) ? max(1, (int) ceil(now()->diffInSeconds($ticketDeadline) / 60)) : 0)
                            @php($canTicket = in_array($row->status, ['expired', 'failed', 'rejected'], true) || $remaining === 0)
                            @if($row->ticket?->submitted_to_center_at)
                                <span class="badge ok">Terkirim</span>
                            @elseif($row->ticket)
                                <span class="badge ok">Sudah Ticket</span><br><span class="muted">{{ $row->ticket->ticket_no }}</span>
                            @else
                                <button class="btn ticket-open" type="button" data-action="{{ route('merchant.cs.topup.ticket', [$merchant, $row]) }}" @disabled(! $canTicket)>{{ $canTicket ? 'Ticket' : 'Tunggu '.$remaining.'m' }}</button>
                            @endif
                        @elseif($active === 'checklist' && $row->status === 'success' && ! $row->is_processed)
                            <form method="post" action="{{ route('api.checklist.update', $row) }}">
                                @csrf @method('PATCH')
                                <input type="hidden" name="checked" value="1">
                                <button class="btn primary">Checklist</button>
                            </form>
                        @else
                            <span class="muted">-</span>
                        @endif
                    </td>
                    <td><textarea name="cs_note_{{ $row->id }}" @unless($row->is_processed) data-cs-note data-note-url="{{ route('api.topup-requests.cs-note', $row) }}" @endunless data-preserve-key="cs-note-{{ $row->id }}" placeholder="{{ $row->is_processed ? '' : 'Tulis keterangan...' }}" @readonly($row->is_processed)>{{ $row->cs_note }}</textarea></td>
                </tr>
            @empty
                <tr><td colspan="{{ $active === 'topup' ? 11 : 10 }}"><div class="empty"><strong>Belum ada transaksi.</strong>Coba ubah periode/filter atau tunggu sync Hilogate berikutnya.</div></td></tr>
            @endforelse
            </tbody>
        </table>
        @else
        <table class="table qris-table {{ $active === 'topup' ? 'topup-table' : ($active === 'history' ? 'history-table' : 'checklist-table') }}">
            <colgroup>
                @if($active === 'topup' || $active === 'history')
                    <col class="col-time-mini">
                    <col class="col-time-mini">
                    <col class="col-duration">
                    <col class="{{ $active === 'history' ? 'col-reference' : 'col-payment' }}">
                    <col class="col-rrn">
                    <col class="col-trx">
                    <col class="col-amount">
                    <col class="col-status">
                    <col class="{{ $active === 'history' ? 'col-note' : 'col-check' }}">
                    <col class="col-follow">
                @else
                    <col class="col-time-mini">
                    <col class="col-time-mini">
                    <col class="col-duration">
                    <col class="col-reference">
                    <col class="col-rrn">
                    <col class="col-amount">
                    <col class="col-status">
                    <col class="col-checked-by">
                    <col class="col-note">
                    <col class="col-check">
                @endif
            </colgroup>
            <thead>
                <tr>
                    @if($active === 'topup')
                        <th>Masuk</th>
                        <th>Sukses</th>
                        <th>Durasi</th>
                        <th>Payment ID</th>
                        <th>RRN</th>
                        <th>TRX ID</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Checklist</th>
                        <th>Tindak Lanjut</th>
                    @elseif($active === 'history')
                        <th>Masuk</th>
                        <th>Sukses</th>
                        <th>Durasi</th>
                        <th>Reference</th>
                        <th>RRN</th>
                        <th>TRX ID</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Keterangan</th>
                        <th>Tindak Lanjut</th>
                    @else
                        <th>Masuk</th>
                        <th>Sukses</th>
                        <th>Durasi</th>
                        <th>Reference</th>
                        <th>RRN</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Checked By</th>
                        <th>Keterangan</th>
                        <th>Checklist</th>
                    @endif
                </tr>
            </thead>
            <tbody>
            @forelse($requests as $row)
                <tr class="{{ $row->status === 'success' && $row->is_processed ? ($active === 'checklist' ? 'checked-row' : 'topup-success-checked-row') : '' }}">
                    @if($active === 'topup')
                        <td class="time-cell">
                            {{ $row->submitted_at?->format('d/m/Y') ?? '-' }}
                            <span>{{ $row->submitted_at?->format('H.i.s') ?? '-' }}</span>
                        </td>
                        <td class="time-cell">
                            {{ $row->succeeded_at?->format('d/m/Y') ?? '-' }}
                            <span>{{ $row->succeeded_at?->format('H.i.s') ?? '-' }}</span>
                        </td>
                        <td>{{ $row->successDurationLabel() }}</td>
                        <td><span class="truncate ref-line">{{ $row->payment_id ?: $row->gateway_ref_id ?: '-' }}</span></td>
                        <td><strong>{{ $row->rrn ?: '-' }}</strong></td>
                        <td><button class="btn trx-view" type="button" data-trx="{{ $row->transaction_id ?: $row->idempotency_key ?: '-' }}">Lihat</button></td>
                        <td><strong>{{ number_format((int) $row->amount, 0, ',', '.') }}</strong></td>
                        <td><span class="status-dot {{ $row->status }}" title="{{ $statusLabel($row->status) }}"></span></td>
                        <td class="checkbox-cell">
                            @if($row->status === 'success')
                                @if($row->is_processed)
                                    @if($canUncheckChecklist)
                                        <form method="post" action="{{ route('api.checklist.update', $row) }}" class="compact-actions">
                                            @csrf @method('PATCH')
                                            <input type="hidden" name="checked" value="0">
                                    <button class="check-icon checked" type="submit" title="Lepas checklist" aria-label="Lepas checklist" onclick="return confirm('Lepas checklist transaksi ini?')"></button>
                                        </form>
                                    @else
                                        <span class="check-icon checked" title="Checked" aria-label="Checked"></span>
                                    @endif
                                @else
                                    <form method="post" action="{{ route('api.checklist.update', $row) }}" class="compact-actions">
                                        @csrf @method('PATCH')
                                        <input type="hidden" name="checked" value="1">
                                    <button class="check-icon" type="submit" title="Checklist" aria-label="Checklist"></button>
                                </form>
                                @endif
                            @else
                                <span class="muted">-</span>
                            @endif
                        </td>
                        <td>
                            @if(in_array($row->status, ['pending', 'expired', 'failed', 'rejected'], true))
                                @php($ticketDeadline = $ticketDeadlineFor($row))
                                @php($remaining = $ticketDeadline && now()->lt($ticketDeadline) ? max(1, (int) ceil(now()->diffInSeconds($ticketDeadline) / 60)) : 0)
                                @php($canTicket = in_array($row->status, ['expired', 'failed', 'rejected'], true) || $remaining === 0)
                                @if($row->ticket?->submitted_to_center_at)
                                    <button class="btn ticket-done" type="button" disabled>Terkirim</button>
                                @elseif($row->ticket)
                                    <button class="btn ticket-done" type="button" disabled>Sudah Ticket</button>
                                @else
                                    <button class="btn ticket-open" type="button" data-action="{{ route('merchant.cs.topup.ticket', [$merchant, $row]) }}" @disabled(! $canTicket)>{{ $canTicket ? 'Ticket' : 'Tunggu '.$remaining.'m' }}</button>
                                @endif
                            @else
                                <span class="muted">-</span>
                            @endif
                        </td>
                    @elseif($active === 'history')
                        <td class="time-cell">
                            {{ $row->submitted_at?->format('d/m/Y') ?? '-' }}
                            <span>{{ $row->submitted_at?->format('H.i.s') ?? '-' }}</span>
                        </td>
                        <td class="time-cell">
                            {{ $row->succeeded_at?->format('d/m/Y') ?? '-' }}
                            <span>{{ $row->succeeded_at?->format('H.i.s') ?? '-' }}</span>
                        </td>
                        <td>{{ $row->successDurationLabel() }}</td>
                        <td><span class="truncate ref-line">{{ $row->customer_reference ?: $row->payment_id ?: $row->gateway_ref_id ?: '-' }}</span></td>
                        <td><strong>{{ $row->rrn ?: '-' }}</strong></td>
                        <td><button class="btn trx-view" type="button" data-trx="{{ $row->transaction_id ?: $row->idempotency_key ?: '-' }}">Lihat</button></td>
                        <td><strong>{{ number_format((int) $row->amount, 0, ',', '.') }}</strong></td>
                        <td><span class="badge {{ $statusClass($row->status) }}">{{ $statusLabel($row->status) }}</span></td>
                        <td>-</td>
                        <td>
                            @if(in_array($row->status, ['pending', 'expired', 'failed', 'rejected'], true))
                                @php($ticketDeadline = $ticketDeadlineFor($row))
                                @php($remaining = $ticketDeadline && now()->lt($ticketDeadline) ? max(1, (int) ceil(now()->diffInSeconds($ticketDeadline) / 60)) : 0)
                                @php($canTicket = in_array($row->status, ['expired', 'failed', 'rejected'], true) || $remaining === 0)
                                @if($row->ticket?->submitted_to_center_at)
                                    <button class="btn ticket-done" type="button" disabled>Terkirim</button>
                                @elseif($row->ticket)
                                    <button class="btn ticket-done" type="button" disabled>Sudah Ticket</button>
                                @else
                                    <button class="btn ticket-open" type="button" data-action="{{ route('merchant.cs.topup.ticket', [$merchant, $row]) }}" @disabled(! $canTicket)>{{ $canTicket ? 'Ticket' : 'Tunggu '.$remaining.'m' }}</button>
                                @endif
                            @else
                                <span class="muted">-</span>
                            @endif
                        </td>
                    @else
                        <td class="time-cell">
                            {{ $row->submitted_at?->format('d/m/Y') ?? '-' }}
                            <span>{{ $row->submitted_at?->format('H.i.s') ?? '-' }}</span>
                        </td>
                        <td class="time-cell">
                            {{ $row->succeeded_at?->format('d/m/Y') ?? '-' }}
                            <span>{{ $row->succeeded_at?->format('H.i.s') ?? '-' }}</span>
                        </td>
                        <td>{{ $row->successDurationLabel() }}</td>
                        <td><span class="truncate ref-line">{{ $row->customer_reference ?: $row->transaction_id ?: $row->gateway_ref_id ?: '-' }}</span></td>
                        <td><strong>{{ $row->rrn ?: '-' }}</strong></td>
                        <td><strong>{{ number_format((int) $row->amount, 0, ',', '.') }}</strong></td>
                        <td><span class="badge ok">SUCCESS</span></td>
                        <td><span class="truncate ref-line">{{ $row->checked_by_email ? $row->checked_by_email.' ('.$row->checked_by_role.')' : '-' }}</span></td>
                        <td>-</td>
                        <td>
                            @if($row->is_processed)
                                @if($canUncheckChecklist)
                                    <form method="post" action="{{ route('api.checklist.update', $row) }}" class="compact-actions">
                                        @csrf @method('PATCH')
                                        <input type="hidden" name="checked" value="0">
                                    <button class="check-icon checked" type="submit" title="Lepas checklist" aria-label="Lepas checklist" onclick="return confirm('Lepas checklist transaksi ini?')"></button>
                                    </form>
                                @else
                                    <span class="check-icon checked" title="Checked" aria-label="Checked"></span>
                                @endif
                            @else
                                <form method="post" action="{{ route('api.checklist.update', $row) }}" class="compact-actions">
                                    @csrf @method('PATCH')
                                    <input type="hidden" name="checked" value="1">
                                    <button class="check-icon" type="submit" title="Checklist" aria-label="Checklist"></button>
                                </form>
                            @endif
                        </td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="10"><div class="empty"><strong>Belum ada transaksi.</strong>Coba ubah periode/filter atau tunggu sync Hilogate berikutnya.</div></td></tr>
            @endforelse
            </tbody>
        </table>
        @endif
        @endif
    </div>
    <div class="pad qris-pagination">
        <div class="pager-summary">
            Showing {{ $paginator->firstItem() ?? 0 }} to {{ $paginator->lastItem() ?? 0 }}{{ $paginator instanceof \Illuminate\Pagination\LengthAwarePaginator ? ' of '.number_format($paginator->total(), 0, ',', '.').' results' : '' }}
        </div>
        <div class="pager-links">
            @if($paginator->onFirstPage())
                <span class="pager disabled">Previous</span>
            @else
                <a class="pager" href="{{ $paginator->previousPageUrl() }}">Previous</a>
            @endif

            @if($paginator instanceof \Illuminate\Pagination\LengthAwarePaginator)
                @foreach($paginator->getUrlRange(max(1, $paginator->currentPage() - 2), min($paginator->lastPage(), $paginator->currentPage() + 2)) as $page => $url)
                    @if($page === $paginator->currentPage())
                        <span class="pager active">{{ $page }}</span>
                    @else
                        <a class="pager" href="{{ $url }}">{{ $page }}</a>
                    @endif
                @endforeach
            @else
                <span class="pager active">{{ $paginator->currentPage() }}</span>
            @endif

            @if($paginator->hasMorePages())
                <a class="pager" href="{{ $paginator->nextPageUrl() }}">Next</a>
            @else
                <span class="pager disabled">Next</span>
            @endif
        </div>
    </div>
</section>
@if($isWorkspace)</div>@endif
<div id="trx-modal" data-live-modal hidden style="position:fixed; inset:0; z-index:20; place-items:center; padding:20px; background:rgba(6,22,47,.45)">
    <div class="card pad" style="width:min(560px, 100%); border-radius:12px">
        <h2>TRX ID</h2>
        <code id="trx-modal-value" style="display:block; margin:14px 0; padding:12px; border-radius:8px; background:#f7faff; overflow-wrap:anywhere"></code>
        <button class="btn" type="button" id="trx-modal-close">Tutup</button>
    </div>
</div>
<div id="ticket-modal" data-live-modal hidden style="position:fixed; inset:0; z-index:20; place-items:center; padding:20px; background:rgba(6,22,47,.45)">
    <form id="ticket-modal-form" method="post" class="card pad" style="width:min(520px, 100%); border-radius:12px">
        @csrf
        <h2>Create Ticket</h2>
        <div class="muted" style="margin:8px 0 14px">Transaksi akan masuk ke menu Tickets. Lampiran diupload saat submit ke CS pusat.</div>
        <input name="note" placeholder="Catatan opsional" style="width:100%; margin-bottom:14px">
        <div class="actions" style="justify-content:flex-end">
            <button class="btn" type="button" id="ticket-modal-close">Batal</button>
            <button class="btn primary" type="submit">Jadikan Ticket</button>
        </div>
    </form>
</div>
<script>
    (() => {
        const openModal = (id) => {
            const modal = document.getElementById(id);
            if (! modal) return;
            modal.hidden = false;
            modal.style.display = 'grid';
        };

        const closeModal = (id) => {
            const modal = document.getElementById(id);
            if (! modal) return;
            modal.hidden = true;
            modal.style.display = 'none';
        };

        document.addEventListener('click', (event) => {
            const trxButton = event.target.closest('.trx-view');
            if (trxButton) {
                event.preventDefault();
                document.getElementById('trx-modal-value').textContent = trxButton.dataset.trx || '-';
                openModal('trx-modal');
                return;
            }

            const ticketButton = event.target.closest('.ticket-open');
            if (ticketButton) {
                event.preventDefault();
                if (ticketButton.disabled) return;
                const form = document.getElementById('ticket-modal-form');
                if (form) form.action = ticketButton.dataset.action || '';
                openModal('ticket-modal');
                return;
            }

            if (event.target.closest('#trx-modal-close')) closeModal('trx-modal');
            if (event.target.closest('#ticket-modal-close')) closeModal('ticket-modal');
        });

        document.addEventListener('change', (event) => {
            if (event.target.matches('[data-period-select]')) {
                const form = event.target.closest('form');
                const from = form?.querySelector('[data-date-from]');
                const to = form?.querySelector('[data-date-to]');
                const today = new Date().toISOString().slice(0, 10);
                const firstDay = new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().slice(0, 10);

                if (event.target.value === 'all') {
                    if (from) from.value = '';
                    if (to) to.value = '';
                    return;
                }

                if (event.target.value === 'today') {
                    if (from) from.value = today;
                    if (to) to.value = today;
                    return;
                }

                if (event.target.value === 'this_month') {
                    if (from) from.value = firstDay;
                    if (to) to.value = today;
                }
            }

            if (! event.target.matches('.ticket-submit input[type="file"]')) return;
            const name = event.target.files && event.target.files[0] ? event.target.files[0].name : 'Belum pilih';
            const label = event.target.closest('.ticket-submit')?.querySelector('.file-name');
            if (label) label.textContent = name;
        });
    })();
</script>
@endsection
