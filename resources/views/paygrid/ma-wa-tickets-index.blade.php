@extends('layouts.paygrid')

@php
    $statusLabel = fn ($status) => App\Support\PayGridLabels::status($status);
    $statusClass = fn ($status) => match ($status) {
        'closed' => 'ok',
        'in_progress' => 'warn',
        default => 'danger',
    };
@endphp

@section('content')
<div class="qris-hero">
    <div>
        <div class="eyebrow">{{ $roleLabel }}</div>
        <h1>Tiket WA (Uji Coba)</h1>
        <p class="sub muted">Menu pilot: tiket dikirim sebagai notifikasi+link ke grup WhatsApp, semua handling tetap di web. Tidak memengaruhi flow tiket biasa.</p>
    </div>
    <a class="btn primary compact-btn" href="{{ route('ma.wa-tickets.create') }}">Buat Tiket Percobaan</a>
</div>

@if(session('status'))
    <section class="card pad section"><span class="badge ok">{{ session('status') }}</span></section>
@endif

<section class="card qris-panel section">
    <div class="qris-toolbar"><h2>Daftar Tiket</h2></div>
    <div class="table-wrap">
        <table class="table qris-table">
            <thead>
                <tr><th>No. Tiket</th><th>Toko</th><th>Kategori</th><th>Status</th><th>Dipegang Oleh</th><th>Dibuat</th><th></th></tr>
            </thead>
            <tbody>
            @forelse($tickets as $ticket)
                <tr>
                    <td data-label="No. Tiket"><strong>{{ $ticket->ticket_no }}</strong></td>
                    <td data-label="Toko">{{ $ticket->merchant?->name ?: '-' }}</td>
                    <td data-label="Kategori">{{ ucfirst($ticket->department) }} &middot; {{ $ticket->category }}</td>
                    <td data-label="Status"><span class="badge {{ $statusClass($ticket->status) }}">{{ $statusLabel($ticket->status) }}</span></td>
                    <td data-label="Dipegang Oleh">{{ $ticket->claimedBy?->name ?: '- belum diambil -' }}</td>
                    <td data-label="Dibuat">{{ $ticket->created_at->timezone('Asia/Jakarta')->format('d M Y H:i') }}</td>
                    <td data-label=""><a class="btn compact-btn" href="{{ route('ma.wa-tickets.show', $ticket) }}">Buka</a></td>
                </tr>
            @empty
                <tr><td colspan="7" class="empty">Belum ada tiket. Klik "Buat Tiket Percobaan" untuk mulai.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="pad">{{ $tickets->links() }}</div>
</section>
@endsection
