@extends('layouts.paygrid')

@php
    $statusLabel = fn ($status) => App\Support\PayGridLabels::status($status);
    $statusClass = fn ($status) => App\Support\PayGridLabels::badge($status);
@endphp

@section('content')
<div class="qris-hero">
    <div>
        <div class="eyebrow">{{ $roleLabel }}</div>
        <h1>Monitor Transaksi Bermasalah</h1>
    </div>
</div>

<form class="card filters" method="get">
    <input class="search" name="q" value="{{ $search }}" placeholder="Cari ticket, toko, RRN, payment id...">
    <div class="actions">
        <select name="merchant_id">
            <option value="0" @selected($merchantFilter === 0)>Semua toko</option>
            @foreach($merchants as $merchant)
                <option value="{{ $merchant->id }}" @selected($merchantFilter === $merchant->id)>{{ $merchant->name }}</option>
            @endforeach
        </select>
        <button class="btn primary">Cari</button>
        <a class="btn" href="{{ route('cs-scope.index') }}">Reset</a>
    </div>
</form>

<section class="card qris-panel section">
    <div class="qris-toolbar"><h2>Tiket Belum Selesai</h2></div>
    <div class="table-wrap">
        <table class="table qris-table">
            <thead>
                <tr><th>Toko</th><th>Ticket</th><th>Issue</th><th>Status</th><th>Dibuat</th><th>Aksi</th></tr>
            </thead>
            <tbody>
            @forelse($tickets as $ticket)
                <tr>
                    <td><strong>{{ $ticket->merchant?->name ?: '-' }}</strong></td>
                    <td><strong>{{ $ticket->ticket_no }}</strong><br><span class="muted truncate ref-line">{{ $ticket->reference ?: '-' }}</span></td>
                    <td><span class="truncate ref-line">{{ $ticket->issue }}</span></td>
                    <td><span class="badge {{ $statusClass($ticket->center_status ?: $ticket->status) }}">{{ $statusLabel($ticket->center_status ?: $ticket->status) }}</span></td>
                    <td class="time-cell">{{ $ticket->created_at?->timezone('Asia/Jakarta')->format('d M Y H.i') ?? '-' }}</td>
                    <td>
                        @if($ticket->submitted_to_center_at)
                            <span class="badge ok">Terkirim</span>
                        @else
                            <a class="btn primary compact-btn" href="{{ route('merchant.cs.tickets', $ticket->merchant) }}">Push Tiket</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty">Tidak ada tiket terbuka pada scope ini.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="qris-pagination pad">
        <div class="pager-summary">Showing {{ $tickets->firstItem() ?? 0 }} to {{ $tickets->lastItem() ?? 0 }}</div>
        <div class="pager-links">
            @if($tickets->onFirstPage())<span class="pager disabled">Prev</span>@else<a class="pager" href="{{ $tickets->previousPageUrl() }}">Prev</a>@endif
            @if($tickets->hasMorePages())<a class="pager" href="{{ $tickets->nextPageUrl() }}">Next</a>@else<span class="pager disabled">Next</span>@endif
        </div>
    </div>
</section>

<section class="card qris-panel section">
    <div class="qris-toolbar"><h2>Transaksi Bermasalah (Belum Jadi Tiket)</h2></div>
    <div class="table-wrap">
        <table class="table qris-table">
            <thead>
                <tr><th>Toko</th><th>Masuk</th><th>Payment ID / RRN</th><th>Amount</th><th>Status</th><th>Aksi</th></tr>
            </thead>
            <tbody>
            @forelse($problemTopups as $row)
                <tr>
                    <td><strong>{{ $row->merchant?->name ?: '-' }}</strong></td>
                    <td class="time-cell">{{ $row->submitted_at?->format('d/m/Y') ?? '-' }}<span>{{ $row->submitted_at?->format('H.i.s') ?? '-' }}</span></td>
                    <td><div class="id-stack"><code>{{ str($row->payment_id ?: $row->gateway_ref_id ?: '-')->limit(18) }}</code><span class="muted">RRN: {{ str($row->rrn ?: '-')->limit(14) }}</span></div></td>
                    <td><strong>{{ number_format((int) $row->amount, 0, ',', '.') }}</strong></td>
                    <td><span class="badge {{ $statusClass($row->status) }}">{{ $statusLabel($row->status) }}</span></td>
                    <td><a class="btn primary compact-btn" href="{{ route('merchant.cs.topup', $row->merchant) }}">Buat Tiket</a></td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty">Tidak ada transaksi bermasalah pada scope ini.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="qris-pagination pad">
        <div class="pager-summary">Showing {{ $problemTopups->firstItem() ?? 0 }} to {{ $problemTopups->lastItem() ?? 0 }}</div>
        <div class="pager-links">
            @if($problemTopups->onFirstPage())<span class="pager disabled">Prev</span>@else<a class="pager" href="{{ $problemTopups->previousPageUrl() }}">Prev</a>@endif
            @if($problemTopups->hasMorePages())<a class="pager" href="{{ $problemTopups->nextPageUrl() }}">Next</a>@else<span class="pager disabled">Next</span>@endif
        </div>
    </div>
</section>
@endsection
