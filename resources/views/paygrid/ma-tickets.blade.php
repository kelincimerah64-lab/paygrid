@extends('layouts.paygrid')

@php
    $deptLabel = fn ($dept) => $dept === 'tech' ? 'Tech' : 'CS';
    $statusLabel = fn ($status) => App\Support\PayGridLabels::status($status);
    $statusClass = fn ($status) => $status === 'in_progress' ? 'warn' : 'danger';
    $categoryLabel = function ($ticket) {
        $categories = $ticket->department === 'tech' ? \App\Services\MerchantTicketService::TECH_CATEGORIES : \App\Services\MerchantTicketService::CS_CATEGORIES;

        return $categories[$ticket->category] ?? $ticket->category;
    };
@endphp

@section('content')
<div class="qris-hero">
    <div>
        <div class="eyebrow">{{ $roleLabel }}</div>
        <h1>Create Ticket</h1>
    </div>
</div>

<section class="card qris-panel section">
    <div class="qris-toolbar"><h2>Toko dengan Fitur Ticket</h2></div>
    <div class="table-wrap">
        <table class="table qris-table">
            <thead>
                <tr><th>Toko</th><th>Agen</th><th>Isu Terbuka</th><th></th></tr>
            </thead>
            <tbody>
            @forelse($merchants as $merchant)
                <tr>
                    <td><strong>{{ $merchant->name }}</strong></td>
                    <td>{{ $merchant->agent?->name ?: '-' }}</td>
                    <td>
                        @php($latest = $merchant->merchantTickets->first())
                        @if($latest)
                            <span class="badge {{ $statusClass($latest->status) }}">{{ $merchant->merchantTickets->count() }} terbuka</span>
                            <span class="muted truncate ref-line" style="max-width:260px">{{ $latest->ticket_no }} &mdash; {{ $deptLabel($latest->department) }}: {{ $categoryLabel($latest) }}</span>
                        @else
                            <span class="muted">Tidak ada tiket terbuka</span>
                        @endif
                    </td>
                    <td><a class="btn primary compact-btn" href="{{ route('merchant.tickets.index', $merchant) }}">Buat Tiket</a></td>
                </tr>
            @empty
                <tr><td colspan="4" class="empty">Belum ada toko dengan fitur Create Ticket aktif.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection
