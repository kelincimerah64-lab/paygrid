@extends('layouts.paygrid')

@php
    $statusLabel = fn ($status) => App\Support\PayGridLabels::status($status);
    $statusPillClass = fn ($status) => match ($status) {
        'closed' => 'closed',
        'in_progress' => 'progress',
        default => 'open',
    };
    $initials = fn (?string $name) => $name ? strtoupper(substr(trim($name), 0, 1)) : '?';
@endphp

@section('content')
<div class="wat-hero">
    <div>
        <p class="eyebrow">{{ $roleLabel }} &middot; Pilot</p>
        <h1>Tiket WA (Uji Coba)</h1>
        <p class="muted" style="margin:6px 0 0; font-size:13px; max-width:56ch">Tiket dikirim sebagai notifikasi+link ke grup WhatsApp, semua handling tetap di web. Tidak memengaruhi flow tiket biasa.</p>
    </div>
    <a class="wat-btn primary" href="{{ route('ma.wa-tickets.create') }}">+ Buat Tiket Percobaan</a>
</div>

@if(session('status'))
    <section class="card pad section"><span class="badge ok">{{ session('status') }}</span></section>
@endif

<section class="wat-panel">
    <div class="wat-panel-head"><h2>Daftar Tiket</h2></div>
    <div class="table-wrap">
        <table class="table qris-table">
            <thead>
                <tr><th>No. Tiket</th><th>Toko</th><th>Kategori</th><th>Status</th><th>Approval</th><th>Dipegang Oleh</th><th>Dibuat</th><th></th></tr>
            </thead>
            <tbody>
            @forelse($tickets as $ticket)
                <tr>
                    <td data-label="No. Tiket"><strong>{{ $ticket->ticket_no }}</strong></td>
                    <td data-label="Toko">{{ $ticket->merchant?->name ?: '-' }}</td>
                    <td data-label="Kategori">{{ ucfirst($ticket->department) }} &middot; {{ $ticket->category }}</td>
                    <td data-label="Status"><span class="wat-pill {{ $statusPillClass($ticket->status) }}">{{ $statusLabel($ticket->status) }}</span></td>
                    <td data-label="Approval">
                        @if($ticket->approval_status === 'waiting')<span class="wat-pill waiting">Menunggu</span>
                        @elseif($ticket->approval_status === 'approved')<span class="wat-pill approved">Disetujui</span>
                        @elseif($ticket->approval_status === 'rejected')<span class="wat-pill rejected">Ditolak</span>
                        @else <span class="muted">&mdash;</span>
                        @endif
                    </td>
                    <td data-label="Dipegang Oleh">
                        @if($ticket->claimedBy)
                            <div style="display:flex; align-items:center; gap:7px"><div class="wat-avatar" style="width:22px;height:22px;font-size:10px">{{ $initials($ticket->claimedBy->name) }}</div>{{ $ticket->claimedBy->name }}</div>
                        @else
                            <span class="muted">belum diambil</span>
                        @endif
                    </td>
                    <td data-label="Dibuat">{{ $ticket->created_at->timezone('Asia/Jakarta')->format('d M, H:i') }}</td>
                    <td data-label=""><a class="btn compact-btn" href="{{ route('ma.wa-tickets.show', $ticket) }}">Buka</a></td>
                </tr>
            @empty
                <tr><td colspan="8" class="empty">Belum ada tiket. Klik "Buat Tiket Percobaan" untuk mulai.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="pad">{{ $tickets->links() }}</div>
</section>
@endsection
