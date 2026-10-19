@extends('layouts.paygrid')

@php
    $statusLabel = fn ($status) => match ($status) { 'open' => 'Belum Diproses', 'closed' => 'Selesai', default => App\Support\PayGridLabels::status($status) };
    $statusClass = fn ($status) => match ($status) {
        'closed' => 'ok',
        'in_progress', 'open' => 'warn',
        default => 'danger',
    };
@endphp

@section('content')
<div class="ticket-detail-crumb"><a href="{{ route('merchant.tickets.simple.create', $merchant) }}">Semua Tiket</a> / <span>Detail Tiket</span></div>

@if(session('status'))
    <section class="card pad section"><span class="badge ok">{{ session('status') }}</span></section>
@endif

<div class="ticket-detail-title-row">
    <h1>{{ $ticket->ticket_no }}</h1>
    <span class="ticket-status-pill {{ $statusClass($ticket->status) }}">{{ $statusLabel($ticket->status) }}</span>
</div>
<p class="ticket-detail-sub">Dibuat {{ $ticket->created_at->timezone('Asia/Jakarta')->format('d M Y, H:i') }}</p>

<div class="ticket-detail-grid">
    <div class="ticket-aside-card">
        <h3>Deskripsi Masalah</h3>
        <p class="ticket-aside-desc">{{ $ticket->description }}</p>

        @if(!empty($ticket->attachments))
            <div class="ticket-attachments-label">Lampiran ({{ count($ticket->attachments) }})</div>
            @foreach($ticket->attachments as $index => $file)
                <div class="ticket-attachment-row">
                    <a class="ticket-attachment-preview" href="{{ route('merchant.tickets.attachment', [$merchant, $ticket, $index]) }}" target="_blank" rel="noopener">
                        <span class="ticket-attachment-icon" aria-hidden="true">&#128196;</span>
                        <span>
                            <span class="name" style="display:block">Lampiran {{ $index + 1 }}</span>
                            <span class="hint">Klik untuk melihat</span>
                        </span>
                    </a>
                    <a class="ticket-attachment-dl" href="{{ route('merchant.tickets.attachment', [$merchant, $ticket, $index, 'download' => 1]) }}" title="Download" aria-label="Download Lampiran {{ $index + 1 }}">&#8681;</a>
                </div>
            @endforeach
        @endif

        @if(filled($ticket->internal_note))
            <div class="ticket-attachments-label">Catatan Dari Tim Support</div>
            <p class="ticket-aside-desc">{{ $ticket->internal_note }}</p>
        @endif
    </div>

    <aside class="ticket-detail-aside">
        <div class="ticket-aside-card">
            <h3>Informasi Tiket</h3>
            <div class="ticket-info-row"><span>ID Tiket</span><strong>#{{ $ticket->id }}</strong></div>
            <div class="ticket-info-row"><span>Dibuat</span><strong>{{ $ticket->created_at->timezone('Asia/Jakarta')->format('d M Y, H:i') }}</strong></div>
            <div class="ticket-info-row"><span>Ditangani</span><strong>{{ $ticket->claimedBy?->name ?? 'Belum ditangani' }}</strong></div>
            <div class="ticket-info-row"><span>Status</span><strong>{{ $statusLabel($ticket->status) }}</strong></div>
        </div>
    </aside>
</div>
@endsection
