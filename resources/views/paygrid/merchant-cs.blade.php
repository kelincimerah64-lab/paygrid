@extends('layouts.paygrid')

@php
    $title = $active === 'history' ? 'History Transaksi' : ($active === 'topup' ? 'Topup Request' : ($active === 'checklist' ? 'Sukses Checklist' : 'Tiket Status'));
    $money = fn ($value) => 'Rp '.number_format((int) ($value ?? 0), 0, ',', '.');
    $statusClass = fn ($status) => match ($status) {
        'success' => 'ok',
        'pending' => 'warn',
        default => 'danger',
    };
    $statusLabel = fn ($status) => strtoupper(str_replace('_', ' ', (string) $status));
    $latestSyncAt = $latestSync?->finished_at?->timezone('Asia/Jakarta')->format('d M Y, H:i:s') ?? 'Belum ada sync';
    $paginator = $active === 'tickets' ? $tickets : $requests;
    $panelTitle = match ($active) {
        'tickets' => 'Daftar Tiket',
        'checklist' => 'Sukses Checklist',
        default => 'Daftar Transaksi',
    };
    $filterBase = ['period' => $period, 'from' => $from, 'to' => $to];
    $isCardActive = fn (?string $status, ?string $processed = null) => request('status') === $status && (string) request('processed') === (string) $processed;
    $ticketPendingMinutes = (int) App\Models\PaygridSetting::value('ticket_pending_minutes', '40');
@endphp

@section('content')
<section class="qris-hero">
    <div>
        <h1>{{ $title }}</h1>
    </div>
</section>

@if(session('status'))
    <div class="card pad section" style="margin-top:0; margin-bottom:12px; background:#ecfff5; border-color:#a4ebc4; color:#008450">{{ session('status') }}</div>
@endif
@if($errors->any())
    <div class="card pad section" style="margin-top:0; margin-bottom:12px; background:#fff1f0; border-color:#f0b4ae; color:#c62828">{{ $errors->first() }}</div>
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
<section class="grid topup-cards">
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
        <span>Available Balance</span>
        <div class="balance-number">{{ number_format((int) ($gatewayBalance['active'] ?? 0), 0, ',', '.') }}</div>
    </div>
    <div class="card pad topup-card pending-balance-card">
        <span>Pending Balance</span>
        <div class="balance-number">{{ number_format((int) ($gatewayBalance['pending'] ?? 0), 0, ',', '.') }}</div>
    </div>
</section>
@elseif($active === 'checklist')
<section class="grid checklist-cards">
    <a class="card pad topup-card active-card" href="{{ route('merchant.cs.checklist', [$merchant] + $filterBase) }}">
        <span>Total Topup Sukses</span>
        <div class="topup-card-row"><small>Jumlah</small><strong>{{ number_format($stats['success'], 0, ',', '.') }}</strong></div>
        <div class="topup-card-row"><small>Nilai</small><strong>{{ number_format($stats['volume_success'], 0, ',', '.') }}</strong></div>
    </a>
    <a class="card pad topup-card" href="{{ route('merchant.cs.checklist', [$merchant] + $filterBase + ['processed' => 'checked']) }}">
        <span>Sukses Sudah Checklist</span>
        <div class="topup-card-row"><small>Jumlah</small><strong>{{ number_format($topupCards['success_checked_count'], 0, ',', '.') }}</strong></div>
        <div class="topup-card-row"><small>Nilai</small><strong>{{ number_format($topupCards['success_checked_amount'], 0, ',', '.') }}</strong></div>
    </a>
    <a class="card pad topup-card" href="{{ route('merchant.cs.checklist', [$merchant] + $filterBase + ['processed' => 'unchecked']) }}">
        <span>Sukses Belum Checklist</span>
        <div class="topup-card-row"><small>Jumlah</small><strong>{{ number_format($topupCards['success_unchecked_count'], 0, ',', '.') }}</strong></div>
        <div class="topup-card-row"><small>Nilai</small><strong>{{ number_format($topupCards['success_unchecked_amount'], 0, ',', '.') }}</strong></div>
    </a>
    <div class="card pad topup-card balance-card">
        <span>Available Balance</span>
        <div class="balance-number">{{ number_format((int) ($gatewayBalance['active'] ?? 0), 0, ',', '.') }}</div>
    </div>
</section>
@else
<section class="grid qris-metrics {{ $active === 'history' ? 'history-metrics' : '' }}">
    <div class="card pad qris-metric primary">
        <span>Total Request</span>
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
            <span>Available Balance</span>
            <strong>{{ number_format((int) ($gatewayBalance['active'] ?? 0), 0, ',', '.') }}</strong>
            <small>Merchant {{ $merchant->name }}</small>
        </div>
    @endif
</section>
@endif

<section class="card qris-panel">
    <div class="qris-toolbar">
        <h2>{{ $panelTitle }}</h2>
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
                    <td><span class="badge {{ $ticket->center_status === 'success' ? 'ok' : (str_starts_with((string) $ticket->center_status, 'issue') ? 'danger' : 'warn') }}">{{ $statusLabel($ticket->center_status ?: $ticket->status) }}</span></td>
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
        <table class="table qris-table {{ $active === 'topup' ? 'topup-table' : ($active === 'history' ? 'history-table' : 'checklist-table') }}">
            <colgroup>
                @if($active === 'topup' || $active === 'history')
                    <col class="col-time">
                    <col class="col-payment">
                    <col class="col-rrn">
                    <col class="col-trx">
                    <col class="col-amount">
                    <col class="col-status">
                    <col class="col-check">
                    <col class="col-follow">
                @else
                    <col class="col-time">
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
                        <th>Timestamp</th>
                        <th>Payment ID</th>
                        <th>RRN</th>
                        <th>TRX ID</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Checklist</th>
                        <th>Tindak Lanjut</th>
                    @elseif($active === 'history')
                        <th>Timestamp</th>
                        <th>Reference</th>
                        <th>RRN</th>
                        <th>TRX ID</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Keterangan</th>
                        <th>Tindak Lanjut</th>
                    @else
                        <th>Timestamp</th>
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
                <tr>
                    @if($active === 'topup')
                        <td class="time-cell">
                            {{ $row->submitted_at?->timezone('Asia/Jakarta')->format('d/m/Y') ?? '-' }}
                            <span>{{ $row->submitted_at?->timezone('Asia/Jakarta')->format('H.i.s') ?? '-' }}</span>
                        </td>
                        <td><span class="truncate ref-line">{{ $row->payment_id ?: $row->gateway_ref_id ?: '-' }}</span></td>
                        <td><strong>{{ $row->rrn ?: '-' }}</strong></td>
                        <td><button class="btn trx-view" type="button" data-trx="{{ $row->transaction_id ?: $row->idempotency_key ?: '-' }}">Lihat</button></td>
                        <td><strong>{{ number_format((int) $row->amount, 0, ',', '.') }}</strong></td>
                        <td><span class="status-dot {{ $row->status }}" title="{{ $statusLabel($row->status) }}"></span></td>
                        <td>
                            @if($row->status === 'success')
                                @if($row->is_processed)
                                    <span class="check-icon checked" title="Checked" aria-label="Checked"></span>
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
                                @php($ticketDeadline = $row->submitted_at ? $row->submitted_at->copy()->addMinutes($ticketPendingMinutes) : ($row->expires_at ? $row->expires_at->copy()->addMinutes(max(0, $ticketPendingMinutes - config('paygrid.topup.expires_in_minutes', 30))) : null))
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
                            {{ $row->submitted_at?->timezone('Asia/Jakarta')->format('d/m/Y') ?? '-' }}
                            <span>{{ $row->submitted_at?->timezone('Asia/Jakarta')->format('H.i.s') ?? '-' }}</span>
                        </td>
                        <td><span class="truncate ref-line">{{ $row->customer_reference ?: $row->payment_id ?: $row->gateway_ref_id ?: '-' }}</span></td>
                        <td><strong>{{ $row->rrn ?: '-' }}</strong></td>
                        <td><button class="btn trx-view" type="button" data-trx="{{ $row->transaction_id ?: $row->idempotency_key ?: '-' }}">Lihat</button></td>
                        <td><strong>{{ number_format((int) $row->amount, 0, ',', '.') }}</strong></td>
                        <td><span class="badge {{ $statusClass($row->status) }}">{{ $statusLabel($row->status) }}</span></td>
                        <td>-</td>
                        <td>
                            @if(in_array($row->status, ['pending', 'expired', 'failed', 'rejected'], true))
                                @php($ticketDeadline = $row->submitted_at ? $row->submitted_at->copy()->addMinutes($ticketPendingMinutes) : ($row->expires_at ? $row->expires_at->copy()->addMinutes(max(0, $ticketPendingMinutes - config('paygrid.topup.expires_in_minutes', 30))) : null))
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
                            {{ $row->submitted_at?->timezone('Asia/Jakarta')->format('d/m/Y') ?? '-' }}
                            <span>{{ $row->submitted_at?->timezone('Asia/Jakarta')->format('H.i.s') ?? '-' }}</span>
                        </td>
                        <td><span class="truncate ref-line">{{ $row->customer_reference ?: $row->transaction_id ?: $row->gateway_ref_id ?: '-' }}</span></td>
                        <td><strong>{{ $row->rrn ?: '-' }}</strong></td>
                        <td><strong>{{ number_format((int) $row->amount, 0, ',', '.') }}</strong></td>
                        <td><span class="badge ok">SUCCESS</span></td>
                        <td><span class="truncate ref-line">{{ $row->checked_by_email ? $row->checked_by_email.' ('.$row->checked_by_role.')' : '-' }}</span></td>
                        <td>-</td>
                        <td>
                            @if($row->is_processed)
                                <span class="check-icon checked" title="Checked" aria-label="Checked"></span>
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
                <tr><td colspan="8"><div class="empty">Belum ada transaksi pada filter ini.</div></td></tr>
            @endforelse
            </tbody>
        </table>
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
<div id="trx-modal" hidden style="position:fixed; inset:0; z-index:20; place-items:center; padding:20px; background:rgba(6,22,47,.45)">
    <div class="card pad" style="width:min(560px, 100%); border-radius:12px">
        <h2>TRX ID</h2>
        <code id="trx-modal-value" style="display:block; margin:14px 0; padding:12px; border-radius:8px; background:#f7faff; overflow-wrap:anywhere"></code>
        <button class="btn" type="button" id="trx-modal-close">Tutup</button>
    </div>
</div>
<div id="ticket-modal" hidden style="position:fixed; inset:0; z-index:20; place-items:center; padding:20px; background:rgba(6,22,47,.45)">
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
