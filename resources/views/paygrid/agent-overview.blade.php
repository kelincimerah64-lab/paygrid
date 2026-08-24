@extends('layouts.paygrid')

@php
    $reportFilters = $reportFilters ?? ['q' => '', 'from' => '', 'to' => ''];
    $money = fn ($value) => 'Rp '.number_format((int) ($value ?? 0), 0, ',', '.');
    $num = fn ($value) => number_format((int) ($value ?? 0), 0, ',', '.');
    $maxVolume = max(1, (int) $merchants->max('metric_volume_success'));
    $statusLabel = fn ($status) => App\Support\PayGridLabels::status($status);
    $statusClass = fn ($status) => App\Support\PayGridLabels::centerStatusBadge($status);
@endphp

@section('content')
<div class="page-head">
    <div>
        <h1>Agent Dashboard</h1>
        <div class="sub">Monitor performa toko, volume transaksi, dan status settlement dari data PayGrid lokal.</div>
    </div>
    <div class="page-actions">
        <a class="btn primary" href="{{ route('agent.create-store') }}">Create Toko</a>
        <a class="btn" href="{{ route('agent.requests') }}">Status Request</a>
    </div>
</div>

<section class="grid cards">
    <div class="card pad metric"><label>Total Toko</label><strong>{{ $num($merchants->count()) }}</strong></div>
    <div class="card pad metric blue"><label>Volume Sukses</label><strong>{{ $money($merchants->sum('metric_volume_success')) }}</strong></div>
    <div class="card pad metric"><label>Transaksi Sukses</label><strong>{{ $num($merchants->sum('metric_trx_total')) }}</strong></div>
    <div class="card pad metric warn-soft"><label>Transaksi Pending</label><strong>{{ $num($merchants->sum('metric_trx_pending')) }}</strong></div>
    <div class="card pad metric success"><label>Estimasi Fee Agen</label><strong>{{ $money($feeTotal ?? 0) }}</strong></div>
</section>

<section class="grid cards section">
    <div class="card pad metric"><label>Total Ticket</label><strong>{{ $num($ticketStats['total'] ?? 0) }}</strong></div>
    <div class="card pad metric warn-soft"><label>Ticket Open</label><strong>{{ $num($ticketStats['open'] ?? 0) }}</strong></div>
    <div class="card pad metric success"><label>Ticket Selesai</label><strong>{{ $num($ticketStats['done'] ?? 0) }}</strong></div>
    <div class="card pad metric"><label>Issue CS Pusat</label><strong>{{ $num($ticketStats['issue'] ?? 0) }}</strong></div>
</section>

<section class="card agent-filter-card section">
    <form class="agent-filter-grid" method="get">
        <label class="agent-filter-search"><span>Pencarian Toko</span><input class="search" name="q" value="{{ $reportFilters['q'] }}" placeholder="Cari merchant, toko, atau slug"></label>
        <label><span>Dari tanggal</span><input type="date" name="from" value="{{ $reportFilters['from'] }}"></label>
        <label><span>Sampai tanggal</span><input type="date" name="to" value="{{ $reportFilters['to'] }}"></label>
        <div class="agent-filter-actions"><button class="btn primary">Submit Filter</button><a class="btn" href="{{ route('agent.overview') }}">Reset</a></div>
    </form>
</section>

<section class="card qris-panel section">
    <div class="qris-toolbar"><div><h2>Report Toko</h2><p class="muted" style="margin:4px 0 0">Ringkasan transaksi per toko sesuai periode filter.</p></div><span class="badge ok">{{ $num($merchants->count()) }} toko</span></div>
    <div class="table-wrap">
        <table class="table qris-table agent-store-report-table">
            <thead><tr><th>Toko</th><th>Tipe</th><th>TRX Sukses</th><th>Sukses</th><th>Pending Transaksi</th><th>Expired</th><th>Volume Sukses</th><th>Saldo Pending HG</th><th>Settlement</th></tr></thead>
            <tbody>
            @forelse($merchants as $merchant)
                <tr>
                    <td><strong>{{ $merchant->name }}</strong><br><span class="muted truncate" style="display:block; max-width:220px">{{ $merchant->merchant_id ?: $merchant->slug }}</span></td>
                    <td><span class="badge {{ $merchant->merchant_type === 'cm' ? 'ok' : 'warn' }}">{{ strtoupper($merchant->merchant_type) }}</span></td>
                    <td>{{ $num($merchant->metric_trx_total) }}</td>
                    <td>{{ $num($merchant->metric_trx_success) }}</td>
                    <td>{{ $num($merchant->metric_trx_pending) }}</td>
                    <td>{{ $num($merchant->metric_trx_expired) }}</td>
                    <td>{{ $money($merchant->metric_volume_success) }}</td>
                    <td>{{ $money($merchant->metric_amount_pending) }}</td>
                    <td>{{ $money($merchant->metric_settlement) }}</td>
                </tr>
            @empty
                <tr><td colspan="9" class="empty">Belum ada data toko untuk filter ini.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>

<section class="card qris-panel section">
    <div class="qris-toolbar"><div><h2>Ticket Toko Saya</h2><p class="muted" style="margin:4px 0 0">Daftar issue/ticket terbaru dari toko-toko di bawah agen ini.</p></div><span class="badge warn">{{ $num($tickets->count()) }} terbaru</span></div>
    <div class="table-wrap">
        <table class="table qris-table agent-ticket-table">
            <thead><tr><th>Dibuat</th><th>Toko</th><th>Ticket</th><th>Reference / RRN</th><th>Customer</th><th>Issue</th><th>Status CS Pusat</th><th>Catatan</th><th>Submitted</th></tr></thead>
            <tbody>
            @forelse($tickets as $ticket)
                @php($topup = $ticket->topupRequest)
                @php($displayStatus = $ticket->center_status ?: $ticket->status)
                <tr>
                    <td class="time-cell"><strong>{{ $ticket->created_at?->timezone('Asia/Jakarta')->format('H:i:s') ?? '-' }}</strong><span>{{ $ticket->created_at?->timezone('Asia/Jakarta')->format('d M Y') ?? '-' }}</span></td>
                    <td><strong>{{ $ticket->merchant?->name ?: '-' }}</strong><br><span class="muted">{{ strtoupper($ticket->merchant?->merchant_type ?: '-') }}</span></td>
                    <td><strong>{{ $ticket->ticket_no }}</strong><br><span class="muted">{{ $statusLabel($ticket->status) }}</span></td>
                    <td><span class="truncate ref-line">{{ $ticket->reference ?: $topup?->payment_id ?: '-' }}</span><br><span class="muted">RRN: {{ $topup?->rrn ?: '-' }}</span></td>
                    <td><strong class="truncate ref-line">{{ $ticket->client_reference ?: $topup?->customer_reference ?: '-' }}</strong></td>
                    <td><span class="truncate ref-line">{{ $ticket->issue }}</span></td>
                    <td><span class="badge {{ $statusClass($displayStatus) }}">{{ $statusLabel($displayStatus) }}</span></td>
                    <td>{{ $ticket->center_note ?: $ticket->note ?: (count($ticket->attachments ?? []) ? count($ticket->attachments).' lampiran' : '-') }}</td>
                    <td class="time-cell">{{ $ticket->submitted_to_center_at?->timezone('Asia/Jakarta')->format('d/m/Y') ?? '-' }}<span>{{ $ticket->submitted_to_center_at?->timezone('Asia/Jakarta')->format('H.i') ?? '' }}</span></td>
                </tr>
            @empty
                <tr><td colspan="9" class="empty"><strong>Belum ada ticket.</strong>Ticket dari toko agen ini akan muncul di sini.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>

<section class="card pad section">
    <h2>Volume Toko Saya</h2>
    <div class="sub">Visual perbandingan volume sukses antar toko.</div>
    @foreach($merchants as $merchant)
        <div class="agent-volume-row">
            <strong class="truncate">{{ $merchant->name }}</strong>
            <div class="agent-volume-track"><div style="width:{{ min(100, ((int) ($merchant->metric_volume_success ?? 0) / $maxVolume) * 100) }}%"></div></div>
            <span>{{ $money($merchant->metric_volume_success) }}</span>
        </div>
    @endforeach
    @if($merchants->isEmpty())
        <div class="empty">Belum ada toko approved untuk agen ini.</div>
    @endif
</section>
@endsection
