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
<div class="ticket-detail-crumb"><a href="{{ route('dept-tickets.index', ['department' => 'general']) }}">Semua Tiket</a> / <span>Detail Tiket</span></div>

@if(session('status'))
    <section class="card pad section"><span class="badge ok">{{ session('status') }}</span></section>
@endif

<div class="ticket-detail-title-row">
    <h1>Permintaan Bantuan</h1>
    <span class="ticket-status-pill {{ $statusClass($ticket->status) }}">{{ $statusLabel($ticket->status) }}</span>
    @if($ticket->status === 'closed')
        <form method="post" action="{{ route('dept-tickets.status', $ticket) }}" style="margin-left:auto">
            @csrf
            <input type="hidden" name="status" value="in_progress">
            <input type="hidden" name="note" value="{{ $ticket->internal_note }}">
            <button class="btn" type="submit">Buka Ulang Tiket</button>
        </form>
    @endif
</div>
<p class="ticket-detail-sub">#{{ $ticket->id }} &middot; Tiket Toko &middot; Dibuat {{ $ticket->created_at->timezone('Asia/Jakarta')->format('d M Y, H:i') }}</p>

<div class="ticket-detail-grid">
    <div class="ticket-aside-card">
        <h3>Deskripsi Masalah</h3>
        <p class="ticket-aside-desc">{{ $ticket->description }}</p>

        @if(!empty($ticket->attachments))
            <div class="ticket-attachments-label">Lampiran ({{ count($ticket->attachments) }})</div>
            @foreach($ticket->attachments as $index => $file)
                <div class="ticket-attachment-row">
                    <a class="ticket-attachment-preview" href="{{ route('dept-tickets.attachment', [$ticket, $index]) }}" target="_blank" rel="noopener">
                        <span class="ticket-attachment-icon" aria-hidden="true">&#128196;</span>
                        <span>
                            <span class="name" style="display:block">Lampiran {{ $index + 1 }}</span>
                            <span class="hint">Klik untuk melihat</span>
                        </span>
                    </a>
                    <a class="ticket-attachment-dl" href="{{ route('dept-tickets.attachment', [$ticket, $index, 'download' => 1]) }}" title="Download" aria-label="Download Lampiran {{ $index + 1 }}">&#8681;</a>
                </div>
            @endforeach
        @endif
    </div>

    <aside class="ticket-detail-aside">
        <div class="ticket-aside-card">
            <h3>Update Status</h3>
            <form method="post" action="{{ route('dept-tickets.status', $ticket) }}">
                @csrf
                <label class="muted" style="display:block; font-size:11.5px; margin-bottom:6px">Status Tiket</label>
                <select name="status">
                    <option value="open" @selected($ticket->status === 'open')>Belum Diproses</option>
                    <option value="in_progress" @selected($ticket->status === 'in_progress')>Diproses</option>
                    <option value="closed" @selected($ticket->status === 'closed')>Selesai</option>
                </select>
                <label class="muted" style="display:block; font-size:11.5px; margin-bottom:6px">Catatan Internal (Opsional)</label>
                <textarea name="note" rows="3" maxlength="2000" placeholder="Contoh: sudah dicek, saldo masuk">{{ $ticket->internal_note }}</textarea>
                <button class="btn primary" type="submit" style="width:100%">Simpan Status</button>
            </form>
        </div>

        <div class="ticket-aside-card">
            <h3>Informasi Tiket</h3>
            <div class="ticket-info-row"><span>ID Tiket</span><strong>#{{ $ticket->id }}</strong></div>
            <div class="ticket-info-row"><span>Pengirim</span><strong>{{ $ticket->createdBy?->name ?? '-' }} (Toko)</strong></div>
            <div class="ticket-info-row"><span>Dibuat</span><strong>{{ $ticket->created_at->timezone('Asia/Jakarta')->format('d M Y, H:i') }}</strong></div>
            <div class="ticket-info-row"><span>Ditangani</span><strong>{{ $ticket->claimedBy?->name ?? 'Belum ditangani' }}</strong></div>
            <div class="ticket-info-row"><span>Status</span><strong>{{ $statusLabel($ticket->status) }}</strong></div>
        </div>
    </aside>
</div>
@endsection
