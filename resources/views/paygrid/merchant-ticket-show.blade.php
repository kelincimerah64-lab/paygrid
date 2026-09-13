@extends('layouts.paygrid')

@php
    $statusLabel = fn ($status) => App\Support\PayGridLabels::status($status);
    $statusClass = fn ($status) => match ($status) {
        'closed' => 'ok',
        'in_progress' => 'warn',
        default => 'danger',
    };
    $deptLabel = app(App\Services\MerchantTicketService::class)->departmentLabel($ticket->department);
    $categoryLabel = app(App\Services\MerchantTicketService::class)->categoryLabel($ticket->department, $ticket->category);
@endphp

@section('content')
<div class="qris-hero">
    <div>
        <div class="eyebrow">{{ $merchant->name }}</div>
        <h1>{{ $ticket->ticket_no }}</h1>
    </div>
    <a class="btn compact-btn" href="{{ route('merchant.tickets.index', $merchant) }}">Kembali</a>
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
    @foreach($ticket->attachments ?? [] as $index => $file)
        <a class="btn compact-btn" style="margin-right:6px" href="{{ route('merchant.tickets.attachment', [$merchant, $ticket, $index]) }}">Lampiran {{ $index + 1 }}</a>
    @endforeach
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
        <form method="post" action="{{ route('merchant.tickets.reply', [$merchant, $ticket]) }}" class="ticket-reply-form">
            @csrf
            <textarea name="body" rows="3" maxlength="2000" required placeholder="Tulis balasan..."></textarea>
            <button class="btn primary compact-btn" type="submit">Kirim Balasan</button>
        </form>
    @else
        <p class="muted" style="margin-top:12px">Tiket ini sudah ditutup.</p>
    @endif
</section>
@endsection
