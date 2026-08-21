@extends('layouts.paygrid')

@php
    $statusClass = fn ($status) => 'center-status-'.str_replace('_', '-', (string) ($status ?: 'not_started'));
@endphp

@section('content')
<div class="qris-hero">
    <div>
        <div class="eyebrow">CS Pusat</div>
        <h1>Dashboard Tiket</h1>
    </div>
</div>

@if(session('status'))
    <section class="card pad section"><span class="badge ok">{{ session('status') }}</span></section>
@endif

<form class="card filters" method="get">
    <input class="search" name="q" value="{{ $search }}" placeholder="Cari ticket, merchant, RRN, payment id...">
    <div class="actions">
        <select name="status">
            <option value="all" @selected($status === 'all')>Semua status</option>
            @foreach($statuses as $value => $label)
                <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <select name="delivery">
            <option value="all" @selected($delivery === 'all')>Semua tiket</option>
            <option value="pending" @selected($delivery === 'pending')>Belum terkirim</option>
            <option value="sent" @selected($delivery === 'sent')>Terkirim</option>
        </select>
        <button class="btn primary">Cari</button>
        <a class="btn" href="{{ route('center-support.tickets') }}">Reset</a>
    </div>
</form>

<section class="card qris-panel section">
    <div class="table-wrap">
        <table class="table qris-table center-ticket-table">
            <thead>
                <tr><th>Ticket</th><th>Tgl</th><th>Transaksi</th><th>Status</th><th>Bukti</th><th>Catatan</th><th>Aksi</th></tr>
            </thead>
            <tbody>
            @forelse($tickets as $ticket)
                @php($topup = $ticket->topupRequest)
                @php($evidenceUrl = App\Http\Controllers\CenterSupportController::evidenceUrl($ticket))
                <tr>
                    <td>
                        <strong>{{ $ticket->ticket_no }}</strong><br><span class="muted">{{ $ticket->merchant?->name ?: '-' }}</span>
                    </td>
                    <td>
                        <span class="time-cell">{{ $ticket->submitted_to_center_at?->timezone('Asia/Jakarta')->format('d M y') ?? '-' }}<span>{{ $ticket->submitted_to_center_at?->timezone('Asia/Jakarta')->format('H.i') ?? '' }}</span></span>
                    </td>
                    <td>
                        <strong class="truncate ref-line">{{ $topup?->payment_id ?: $ticket->payment_id ?: $ticket->client_reference ?: '-' }}</strong>
                    </td>
                    <td>
                        <form id="ticket-update-{{ $ticket->id }}" method="post" action="{{ route('center-support.tickets.update', $ticket) }}" class="center-update-row">
                            @csrf
                            <select name="center_status" class="center-status {{ $statusClass($ticket->center_status) }}" onchange="this.className = 'center-status center-status-' + this.value.replace(/_/g, '-')" @disabled($ticket->center_updated_at)>
                                @foreach($statuses as $value => $label)
                                    <option class="{{ $statusClass($value) }}" value="{{ $value }}" @selected($ticket->center_status === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </form>
                    </td>
                    <td>
                        @if($evidenceUrl)
                            <a class="btn ghost compact-btn" href="{{ $evidenceUrl }}" target="_blank" rel="noopener">Bukti</a>
                        @else
                            <span class="muted">-</span>
                        @endif
                    </td>
                    <td>
                        <input form="ticket-update-{{ $ticket->id }}" name="center_note" value="{{ $ticket->center_note }}" placeholder="Catatan CS pusat" @disabled($ticket->center_updated_at)>
                    </td>
                    <td>
                        @if($ticket->center_updated_at)
                            <button type="button" class="btn compact-btn" disabled>Terkirim</button>
                        @else
                            <button form="ticket-update-{{ $ticket->id }}" class="btn primary compact-btn">Simpan</button>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="empty">Belum ada tiket yang masuk ke CS pusat.</td></tr>
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
@endsection
