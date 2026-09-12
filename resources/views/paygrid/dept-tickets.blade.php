@extends('layouts.paygrid')

@php
    $statusLabel = fn ($status) => App\Support\PayGridLabels::status($status);
    $statusClass = fn ($status) => match ($status) {
        'closed' => 'ok',
        'in_progress' => 'warn',
        default => 'danger',
    };
    $deptLabel = fn ($dept) => $dept === 'tech' ? 'Tech Support' : 'CS';
@endphp

@section('content')
<div class="qris-hero">
    <div>
        <div class="eyebrow">{{ $roleLabel }}</div>
        <h1>Dashboard Tiket Toko</h1>
    </div>
</div>

@if(session('status'))
    <section class="card pad section"><span class="badge ok">{{ session('status') }}</span></section>
@endif

<form class="card filters" method="get">
    <input class="search" name="q" value="{{ $search }}" placeholder="Cari ticket, toko...">
    <div class="actions">
        <select name="status">
            <option value="all" @selected($status === 'all')>Semua status</option>
            <option value="open" @selected($status === 'open')>Open</option>
            <option value="in_progress" @selected($status === 'in_progress')>In Progress</option>
            <option value="closed" @selected($status === 'closed')>Closed</option>
        </select>
        <button class="btn primary">Cari</button>
        <a class="btn" href="{{ route('dept-tickets.index') }}">Reset</a>
    </div>
</form>

<section class="card qris-panel section">
    <div class="table-wrap">
        <table class="table qris-table ticket-table">
            <thead>
                <tr><th>Toko</th><th>Ticket</th><th>Tujuan</th><th>Menu</th><th>Update Terakhir</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
            @forelse($tickets as $ticket)
                @php($categoryLabel = ($ticket->department === 'tech' ? \App\Services\MerchantTicketService::TECH_CATEGORIES : \App\Services\MerchantTicketService::CS_CATEGORIES)[$ticket->category] ?? $ticket->category)
                <tr>
                    <td><strong>{{ $ticket->merchant?->name ?: '-' }}</strong></td>
                    <td>{{ $ticket->ticket_no }}</td>
                    <td>{{ $deptLabel($ticket->department) }}</td>
                    <td><span class="truncate ref-line">{{ $categoryLabel }}</span></td>
                    <td><span class="muted">{{ $ticket->last_message_at?->timezone('Asia/Jakarta')->format('d M Y H:i') ?? '-' }}</span></td>
                    <td><span class="badge {{ $statusClass($ticket->status) }}">{{ $statusLabel($ticket->status) }}</span></td>
                    <td><a class="btn compact-btn" href="{{ route('dept-tickets.show', $ticket) }}">Buka</a></td>
                </tr>
            @empty
                <tr><td colspan="7" class="empty">Tidak ada tiket.</td></tr>
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
