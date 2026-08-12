@extends('layouts.paygrid')

@php
    $money = fn ($value) => number_format((int) ($value ?? 0), 0, ',', '.');
    $statusClass = fn ($status) => $status === 'success' ? 'ok' : (in_array($status, ['expired', 'failed', 'rejected'], true) ? 'danger' : 'warn');
    $statusLabel = fn ($status) => ucfirst(str_replace('_', ' ', $status ?: '-'));
@endphp

@section('content')
<div class="qris-hero">
    <div>
        <div class="eyebrow">{{ strtoupper($merchant->merchant_type) }} Finance</div>
        <h1>{{ $title }}</h1>
    </div>
</div>

@if(session('status'))
    <section class="card pad section"><span class="badge ok">{{ session('status') }}</span></section>
@endif

<section class="grid qris-metrics">
    <div class="card pad qris-metric">
        <span>Total Transaksi</span>
        <strong>{{ $money($stats['total'] ?? 0) }}</strong>
        <small>{{ $money($stats['total'] ?? 0) }} transaksi periode ini; {{ $money($stats['today_total'] ?? 0) }} hari ini</small>
    </div>
    <div class="card pad qris-metric">
        <span>Total Volume</span>
        <strong>{{ $money($stats['volume_total'] ?? 0) }}</strong>
        <small>Periode ini; Rp {{ $money($stats['today_volume'] ?? 0) }} hari ini</small>
    </div>
    <div class="card pad qris-metric primary">
        <span>Available Balance</span>
        <strong>{{ $money($gatewayBalance['active'] ?? 0) }}</strong>
        <small>Saldo settlement</small>
    </div>
    <div class="card pad qris-metric pending">
        <span>Pending Balance</span>
        <strong>{{ $money($gatewayBalance['pending'] ?? 0) }}</strong>
        <small>Saldo pending</small>
    </div>
</section>

@if($active === 'settlement')
    <section class="card pad section">
        <div class="split">
            <div>
                <div class="eyebrow">Settlement</div>
                <h2>Coming soon</h2>
                <p class="muted">Menu settlement belum dibuka untuk eksekusi. Nanti bagian ini akan berisi approval settlement, status pencairan, audit, dan rekonsiliasi saldo.</p>
            </div>
            <div class="mini-grid">
                <div class="fee-pill"><span>Net Settlement</span><strong>Rp {{ $money($stats['net_success'] ?? 0) }}</strong></div>
                <div class="fee-pill"><span>Fee</span><strong>Rp {{ $money($stats['fee_amount'] ?? 0) }}</strong></div>
                <div class="fee-pill"><span>Success</span><strong>{{ $money($stats['success'] ?? 0) }}</strong></div>
                <div class="fee-pill"><span>Pending</span><strong>{{ $money($stats['pending'] ?? 0) }}</strong></div>
            </div>
        </div>
    </section>
@endif

@if($active !== 'settlement')
<section class="card qris-panel section">
    <form class="qris-toolbar" method="get">
        <div>
            <strong>{{ $active === 'report' ? 'Report Transaksi Toko' : 'Data Transaksi Bulanan' }}</strong>
            <div class="muted">Last sync: {{ $latestSync?->finished_at?->timezone('Asia/Jakarta')->format('d M Y H:i:s') ?? '-' }}</div>
        </div>
        <div class="qris-filters">
            <select name="period" data-period-select>
                <option value="this_month" @selected($period === 'this_month')>Bulan ini</option>
                <option value="last_month" @selected($period === 'last_month')>Bulan lalu</option>
                <option value="all" @selected($period === 'all')>Semua data</option>
            </select>
            <input type="date" name="from" value="{{ $from }}" data-date-filter>
            <input type="date" name="to" value="{{ $to }}" data-date-filter>
            <select name="status">
                <option value="all" @selected($status === 'all')>Semua status</option>
                <option value="success" @selected($status === 'success')>Success</option>
                <option value="pending" @selected($status === 'pending')>Pending</option>
                <option value="expired" @selected($status === 'expired')>Expired</option>
            </select>
            <input class="search" name="q" value="{{ $search }}" placeholder="Cari payment, trx, RRN...">
            <button class="btn primary">Filter</button>
        </div>
    </form>

    <div class="table-wrap">
        <table class="table qris-table finance-table">
            <thead>
                <tr>
                    <th class="col-time">Tanggal</th>
                    <th>Reference</th>
                    <th>RRN</th>
                    <th>Gross</th>
                    <th>Fee</th>
                    <th>Net</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            @forelse($transactions as $transaction)
                <tr>
                    <td class="time-cell">{{ $transaction->submitted_at?->timezone('Asia/Jakarta')->format('d M y') ?? '-' }}<span>{{ $transaction->submitted_at?->timezone('Asia/Jakarta')->format('H:i:s') ?? '-' }}</span></td>
                    <td><strong class="truncate ref-line">{{ $transaction->payment_id ?: ($transaction->gateway_ref_id ?: '-') }}</strong><span class="muted truncate ref-line">TRX: {{ $transaction->transaction_id ?: '-' }}</span></td>
                    <td class="truncate">{{ $transaction->rrn ?: '-' }}</td>
                    <td>Rp {{ $money($transaction->amount) }}</td>
                    <td>Rp {{ $money($transaction->fee_amount) }}</td>
                    <td><strong>Rp {{ $money($transaction->net_amount) }}</strong></td>
                    <td><span class="badge {{ $statusClass($transaction->status) }}">{{ $statusLabel($transaction->status) }}</span></td>
                </tr>
            @empty
                <tr><td colspan="7" class="empty">Belum ada transaksi pada filter ini.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="qris-pagination pad">
        <div class="pager-summary">Showing {{ $transactions->firstItem() ?? 0 }} to {{ $transactions->lastItem() ?? 0 }}</div>
        <div class="pager-links">
            @if($transactions->onFirstPage())
                <span class="pager disabled">Prev</span>
            @else
                <a class="pager" href="{{ $transactions->previousPageUrl() }}">Prev</a>
            @endif
            @if($transactions->hasMorePages())
                <a class="pager" href="{{ $transactions->nextPageUrl() }}">Next</a>
            @else
                <span class="pager disabled">Next</span>
            @endif
        </div>
    </div>
</section>

@if($active === 'report')
<section class="card qris-panel section">
    <div class="qris-toolbar">
        <div>
            <strong>Report Settlement Toko</strong>
            <div class="muted">Berisi settlement resmi dari cache endpoint Hilogate /settlements.</div>
        </div>
        <form class="qris-filters" method="get">
            <input type="hidden" name="period" value="{{ $period }}">
            <input type="hidden" name="from" value="{{ $from }}">
            <input type="hidden" name="to" value="{{ $to }}">
            <input type="hidden" name="status" value="{{ $status }}">
            <input type="hidden" name="q" value="{{ $search }}">
            <select name="settlement_status">
                <option value="all" @selected($settlementStatus === 'all')>Semua settlement</option>
                <option value="SUCCESS" @selected($settlementStatus === 'SUCCESS')>Success</option>
                <option value="PENDING" @selected($settlementStatus === 'PENDING')>Pending</option>
                <option value="FAILED" @selected($settlementStatus === 'FAILED')>Failed</option>
                <option value="DONE" @selected($settlementStatus === 'DONE')>Done</option>
                <option value="SETTLED" @selected($settlementStatus === 'SETTLED')>Settled</option>
            </select>
            <button class="btn primary">Filter</button>
        </form>
    </div>

    <div class="table-wrap">
        <table class="table qris-table finance-table settlement-table">
            <thead>
                <tr>
                    <th class="col-time">Settlement</th>
                    <th>Reference</th>
                    <th>Batch</th>
                    <th>TRX</th>
                    <th>Gross</th>
                    <th>Fee</th>
                    <th>Net</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            @forelse($settlementRows as $settlement)
                <tr>
                    <td class="time-cell">{{ $settlement->settlement_date?->format('d M y') ?? '-' }}<span>{{ $settlement->processed_at?->timezone('Asia/Jakarta')->format('H:i:s') ?? '-' }}</span></td>
                    <td><strong class="truncate ref-line">{{ $settlement->reference }}</strong><span class="muted truncate ref-line">{{ $settlement->settlement_type ?: '-' }}</span></td>
                    <td><strong class="truncate ref-line">{{ $settlement->batch_name ?: '-' }}</strong><span class="muted truncate ref-line">{{ trim(($settlement->batch_from ?: '').' - '.($settlement->batch_until ?: ''), ' -') ?: '-' }}</span></td>
                    <td>{{ $money($settlement->trx_count) }}</td>
                    <td>Rp {{ $money($settlement->total_amount) }}</td>
                    <td>Rp {{ $money($settlement->total_fee) }}</td>
                    <td><strong>Rp {{ $money($settlement->net_amount) }}</strong></td>
                    <td><span class="badge {{ $statusClass(strtolower((string) $settlement->status)) }}">{{ $statusLabel($settlement->status) }}</span></td>
                </tr>
            @empty
                <tr><td colspan="8" class="empty">Belum ada data settlement HG pada filter ini. Jalankan sync settlement untuk mengambil cache terbaru.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="qris-pagination pad">
        <div class="pager-summary">Showing {{ $settlementRows->firstItem() ?? 0 }} to {{ $settlementRows->lastItem() ?? 0 }}</div>
        <div class="pager-links">
            @if($settlementRows->onFirstPage())
                <span class="pager disabled">Prev</span>
            @else
                <a class="pager" href="{{ $settlementRows->previousPageUrl() }}">Prev</a>
            @endif
            @if($settlementRows->hasMorePages())
                <a class="pager" href="{{ $settlementRows->nextPageUrl() }}">Next</a>
            @else
                <span class="pager disabled">Next</span>
            @endif
        </div>
    </div>
</section>
@endif
@endif
@endsection
