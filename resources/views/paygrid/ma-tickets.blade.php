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
                <tr><th>Toko</th><th>Agen</th><th>Isu Terbuka</th><th>Tujuan</th><th>Menu</th><th></th></tr>
            </thead>
            <tbody>
            @forelse($merchants as $merchant)
                @php($latest = $merchant->merchantTickets->first())
                <tr>
                    <td><strong>{{ $merchant->name }}</strong></td>
                    <td>{{ $merchant->agent?->name ?: '-' }}</td>
                    <td>
                        @if($latest)
                            <span class="badge {{ $statusClass($latest->status) }}">{{ $merchant->merchantTickets->count() }} terbuka</span>
                            <span class="muted truncate ref-line" style="max-width:180px">{{ $latest->ticket_no }}</span>
                        @else
                            <span class="muted">Tidak ada tiket terbuka</span>
                        @endif
                    </td>
                    <td>{{ $latest ? $deptLabel($latest->department) : '-' }}</td>
                    <td><span class="truncate ref-line" style="max-width:180px">{{ $latest ? $categoryLabel($latest) : '-' }}</span></td>
                    <td><a class="btn primary compact-btn" href="{{ route('merchant.tickets.index', $merchant) }}">Buat Tiket</a></td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty">Belum ada toko dengan fitur Create Ticket aktif.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection
