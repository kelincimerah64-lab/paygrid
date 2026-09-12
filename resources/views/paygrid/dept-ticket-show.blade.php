@extends('layouts.paygrid')

@php
    $statusLabel = fn ($status) => App\Support\PayGridLabels::status($status);
    $statusClass = fn ($status) => match ($status) {
        'closed' => 'ok',
        'in_progress' => 'warn',
        default => 'danger',
    };
    $deptLabel = $ticket->department === 'tech' ? 'Tech Support' : 'CS';
    $categoryLabel = ($ticket->department === 'tech' ? \App\Services\MerchantTicketService::TECH_CATEGORIES : \App\Services\MerchantTicketService::CS_CATEGORIES)[$ticket->category] ?? $ticket->category;
@endphp

@section('content')
<div class="qris-hero">
    <div>
        <div class="eyebrow">{{ $ticket->merchant?->name ?: '-' }}</div>
        <h1>{{ $ticket->ticket_no }}</h1>
    </div>
    <a class="btn compact-btn" href="{{ route('dept-tickets.index') }}">Kembali</a>
</div>

@if(session('status'))
    <section class="card pad section"><span class="badge ok">{{ session('status') }}</span></section>
@endif

<section class="card qris-panel section">
    <div class="qris-toolbar"><h2>Detail Tiket</h2><span class="badge {{ $statusClass($ticket->status) }}">{{ $statusLabel($ticket->status) }}</span></div>
    <div class="approval-detail-grid">
        <div class="fee-pill"><span>Tujuan</span><strong>{{ $deptLabel }}</strong></div>
        <div class="fee-pill"><span>Menu</span><strong>{{ $categoryLabel }}</strong></div>
        <div class="fee-pill"><span>Dibuat</span><strong>{{ $ticket->created_at->timezone('Asia/Jakarta')->format('d M Y H:i') }}</strong></div>
    </div>
    <p style="margin-top:12px; white-space:pre-wrap">{{ $ticket->description }}</p>
    @if($ticket->attachment_path)
        <a class="btn compact-btn" href="{{ route('merchant.tickets.attachment', [$ticket->merchant, $ticket]) }}">Lihat Lampiran</a>
    @endif

    <form method="post" action="{{ route('dept-tickets.status', $ticket) }}" style="margin-top:12px; display:flex; gap:8px; align-items:center">
        @csrf
        <select name="status">
            <option value="open" @selected($ticket->status === 'open')>Open</option>
            <option value="in_progress" @selected($ticket->status === 'in_progress')>In Progress</option>
            <option value="closed" @selected($ticket->status === 'closed')>Closed</option>
        </select>
        <button class="btn compact-btn" type="submit">Update Status</button>
    </form>
</section>

<section class="card qris-panel section">
    <div class="qris-toolbar"><h2>Percakapan</h2></div>
    <div class="ticket-thread">
        @forelse($ticket->messages as $message)
            <div class="ticket-message {{ $message->is_staff ? 'staff' : 'store' }}">
                <div class="ticket-message-meta"><strong>{{ $message->is_staff ? $deptLabel : ($message->user->name ?? 'Toko') }}</strong><span class="muted">{{ $message->created_at->timezone('Asia/Jakarta')->format('d M Y H:i') }}</span></div>
                <div class="ticket-message-body">{{ $message->body }}</div>
            </div>
        @empty
            <p class="muted">Belum ada balasan.</p>
        @endforelse
    </div>

    @if($ticket->status !== 'closed')
        <form method="post" action="{{ route('dept-tickets.reply', $ticket) }}" class="ticket-reply-form">
            @csrf
            <textarea name="body" rows="3" maxlength="2000" required placeholder="Tulis balasan ke toko..."></textarea>
            <button class="btn primary compact-btn" type="submit">Kirim Balasan</button>
        </form>
    @else
        <p class="muted" style="margin-top:12px">Tiket ini sudah ditutup.</p>
    @endif
</section>
@endsection
