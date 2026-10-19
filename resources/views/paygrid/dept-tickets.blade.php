@extends('layouts.paygrid')

@php
    $statusLabel = fn ($status) => match ($status) { 'open' => 'Belum Diproses', 'closed' => 'Selesai', default => App\Support\PayGridLabels::status($status) };
    $statusClass = fn ($status) => match ($status) {
        'closed' => 'ok',
        'in_progress', 'open' => 'warn',
        default => 'danger',
    };
    $deptLabel = fn ($dept) => app(App\Services\MerchantTicketService::class)->departmentLabel($dept);
    $categoryLabel = fn ($ticket) => app(App\Services\MerchantTicketService::class)->categoryLabel($ticket->department, $ticket->category);
    $departmentFilter = request('department', '');
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
        <select name="department">
            <option value="" @selected($departmentFilter === '')>Semua tujuan</option>
            <option value="cs" @selected($departmentFilter === 'cs')>CS</option>
            <option value="tech" @selected($departmentFilter === 'tech')>Tech Support</option>
            <option value="finance" @selected($departmentFilter === 'finance')>Finance</option>
            <option value="general" @selected($departmentFilter === 'general')>Tiket Toko</option>
        </select>
        <select name="status">
            <option value="all" @selected($status === 'all')>Semua status</option>
            <option value="open" @selected($status === 'open')>Belum Diproses</option>
            <option value="in_progress" @selected($status === 'in_progress')>Diproses</option>
            <option value="closed" @selected($status === 'closed')>Selesai</option>
        </select>
        <button class="btn primary">Cari</button>
        <a class="btn" href="{{ route('dept-tickets.index') }}">Reset</a>
    </div>
</form>

<section class="card qris-panel section">
    <div class="ticket-list">
        @forelse($tickets as $ticket)
            <a class="ticket-list-row" href="{{ route('dept-tickets.show', $ticket) }}">
                <div class="ticket-list-icon" aria-hidden="true">{{ strtoupper(mb_substr($ticket->merchant?->name ?: '-', 0, 1)) }}</div>
                <div class="ticket-list-main">
                    <div class="ticket-list-no">{{ $ticket->ticket_no }} &middot; {{ $ticket->merchant?->name ?: '-' }}</div>
                    <div class="ticket-list-sub"><span>{{ $deptLabel($ticket->department) }}</span><span>&middot;</span><span class="truncate ref-line">{{ $categoryLabel($ticket) }}</span></div>
                </div>
                <div class="ticket-list-meta">
                    <span class="badge {{ $statusClass($ticket->status) }}">{{ $statusLabel($ticket->status) }}</span>
                    <span class="ticket-list-time">{{ $ticket->last_message_at?->timezone('Asia/Jakarta')->format('d M Y H:i') ?? '-' }}</span>
                </div>
            </a>
        @empty
            <div class="ticket-list-empty">Tidak ada tiket.</div>
        @endforelse
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
