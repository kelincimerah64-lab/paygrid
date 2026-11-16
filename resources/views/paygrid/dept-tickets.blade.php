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
    $restrictedDept = str_starts_with(auth()->user()->role, 'dept_') ? substr(auth()->user()->role, 5) : null;
    $notifyDepartments = $restrictedDept
        ? [$restrictedDept]
        : [...App\Services\MerchantTicketService::DEPARTMENTS, App\Services\MerchantTicketService::SIMPLE_DEPARTMENT];
    $canOpen = fn ($ticket) => $restrictedDept === null
        || $ticket->status === 'closed'
        || $ticket->claimed_by_user_id === null
        || (int) $ticket->claimed_by_user_id === (int) auth()->id();
@endphp

@section('content')
<div class="qris-hero">
    <div>
        <div class="eyebrow">{{ $roleLabel }}</div>
        <h1>Dashboard Tiket Toko</h1>
    </div>
</div>

@if(session('status'))
    <div class="status-banner"><span class="badge ok">{{ session('status') }}</span></div>
@endif

<form class="card filters" method="get">
    <input class="search" name="q" value="{{ $search }}" placeholder="Cari ticket, toko...">
    <div class="actions">
        <select name="department">
            <option value="" @selected($departmentFilter === '')>Semua tujuan</option>
            <option value="cs" @selected($departmentFilter === 'cs')>CS</option>
            <option value="dev" @selected($departmentFilter === 'dev')>Dev</option>
            <option value="tech_supp" @selected($departmentFilter === 'tech_supp')>Tech Support</option>
            <option value="finance" @selected($departmentFilter === 'finance')>Finance</option>
            <option value="settlement" @selected($departmentFilter === 'settlement')>Settlement</option>
            <option value="fraud" @selected($departmentFilter === 'fraud')>Fraud</option>
            <option value="dispute" @selected($departmentFilter === 'dispute')>Dispute</option>
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
            @if(! $ticket->claimed_by_user_id && $ticket->status === 'open')
                <form method="post" action="{{ route('dept-tickets.claim', $ticket) }}">
                    @csrf
                    <button type="submit" class="ticket-list-row ticket-list-row-btn">
                        @include('paygrid.partials.dept-ticket-row', ['ticket' => $ticket, 'deptLabel' => $deptLabel, 'categoryLabel' => $categoryLabel, 'statusLabel' => $statusLabel, 'statusClass' => $statusClass])
                    </button>
                </form>
            @elseif($canOpen($ticket))
                <a class="ticket-list-row" href="{{ route('dept-tickets.show', $ticket) }}">
                    @include('paygrid.partials.dept-ticket-row', ['ticket' => $ticket, 'deptLabel' => $deptLabel, 'categoryLabel' => $categoryLabel, 'statusLabel' => $statusLabel, 'statusClass' => $statusClass])
                </a>
            @else
                <div class="ticket-list-row ticket-list-row-locked">
                    @include('paygrid.partials.dept-ticket-row', ['ticket' => $ticket, 'deptLabel' => $deptLabel, 'categoryLabel' => $categoryLabel, 'statusLabel' => $statusLabel, 'statusClass' => $statusClass])
                </div>
            @endif
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

@push('scripts')
<script src="{{ asset('js/paygrid-realtime.js') }}?v={{ filemtime(public_path('js/paygrid-realtime.js')) }}"></script>
<script>
(function () {
    if (!window.PayGridRealtime) return;
    var realtime = window.PayGridRealtime.connect({
        key: @json(config('broadcasting.connections.reverb.key')),
        host: @json(config('broadcasting.connections.reverb.options.host')),
        port: @json(config('broadcasting.connections.reverb.options.port')),
        scheme: @json(config('broadcasting.connections.reverb.options.scheme')),
    });
    var departments = @json($notifyDepartments);
    departments.forEach(function (dept) {
        realtime.subscribePrivate('private-department-tickets.' + dept, 'ticket.created', function () {
            window.PayGridRealtime.playNotifySound();
            var search = document.querySelector('.filters .search');
            if (document.activeElement === search) return;
            window.location.reload();
        });
    });
})();
</script>
@endpush
