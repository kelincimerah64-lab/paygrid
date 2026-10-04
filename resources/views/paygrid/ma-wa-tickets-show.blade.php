@extends('layouts.paygrid')

@php
    $statusLabel = fn ($status) => App\Support\PayGridLabels::status($status);
    $statusClass = fn ($status) => match ($status) {
        'closed' => 'ok',
        'in_progress' => 'warn',
        default => 'danger',
    };
    $toToko = $ticket->messages->where('is_internal', false);
    $internal = $ticket->messages->where('is_internal', true);
@endphp

@section('content')
<div class="qris-hero">
    <div>
        <div class="eyebrow">{{ $ticket->merchant?->name ?: '-' }}</div>
        <h1>{{ $ticket->ticket_no }}</h1>
    </div>
    <a class="btn compact-btn" href="{{ route('ma.wa-tickets.index') }}">Kembali</a>
</div>

@if(session('status'))
    <section class="card pad section"><span class="badge ok">{{ session('status') }}</span></section>
@endif

<section class="card qris-panel section">
    <div class="qris-toolbar"><h2>Detail Tiket</h2><span class="badge {{ $statusClass($ticket->status) }}">{{ $statusLabel($ticket->status) }}</span></div>
    <div class="approval-detail-grid">
        <div class="fee-pill"><span>Department</span><strong>{{ ucfirst($ticket->department) }}</strong></div>
        <div class="fee-pill"><span>Kategori</span><strong>{{ $ticket->category }}</strong></div>
        <div class="fee-pill"><span>Dibuat</span><strong>{{ $ticket->created_at->timezone('Asia/Jakarta')->format('d M Y H:i') }}</strong></div>
        @foreach($ticket->metadata ?? [] as $key => $value)
            <div class="fee-pill"><span>{{ ucfirst(str_replace('_', ' ', $key)) }}</span><strong>{{ is_numeric($value) ? 'Rp'.number_format((float) $value, 0, ',', '.') : $value }}</strong></div>
        @endforeach
    </div>
    <p style="margin-top:12px; white-space:pre-wrap">{{ $ticket->description }}</p>

    @if($ticket->approval_status === 'waiting')
        <div class="pad" style="padding-left:0; padding-right:0">
            <span class="badge warn" style="margin-bottom:10px; display:inline-block">Menunggu Approval</span>
            <form method="post" action="{{ route('ma.wa-tickets.approve', $ticket) }}" style="display:inline-block; margin-right:8px">
                @csrf
                <button class="btn primary compact-btn" type="submit">Approve</button>
            </form>
            <form method="post" action="{{ route('ma.wa-tickets.reject', $ticket) }}" style="display:inline-block">
                @csrf
                <button class="btn compact-btn" type="submit" style="color:#c62828">Reject</button>
            </form>
        </div>
    @elseif($ticket->approval_status === 'rejected')
        <div class="pad" style="padding-left:0; padding-right:0">
            <span class="badge danger">Ditolak oleh {{ $ticket->approval_by }}{{ $ticket->approval_note ? ' — '.$ticket->approval_note : '' }}</span>
        </div>
    @else
    <div class="pad" style="padding-left:0; padding-right:0">
        @if($ticket->approval_status === 'approved')
            <span class="badge ok" style="margin-bottom:10px; display:inline-block">Disetujui oleh {{ $ticket->approval_by }}</span>
        @endif
        @if(!$ticket->claimed_by_user_id)
            <form method="post" action="{{ route('ma.wa-tickets.claim', $ticket) }}">
                @csrf
                <button class="btn primary" type="submit" @disabled($ticket->status === 'closed')>Ambil Tiket Ini</button>
            </form>
        @else
            <div class="fee-pill" style="display:inline-flex; margin-bottom:10px"><span>Dipegang oleh</span><strong>{{ $ticket->claimedBy?->name }} &middot; {{ $ticket->claimed_at?->timezone('Asia/Jakarta')->format('d M Y H:i') }}</strong></div>
            @if($ticket->status !== 'closed')
                <form method="post" action="{{ route('ma.wa-tickets.transfer', $ticket) }}" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap">
                    @csrf
                    <select name="to_user_id">
                        <option value="">Lepas ke Antrean</option>
                        @foreach($teammates as $mate)
                            <option value="{{ $mate->id }}" @selected($mate->id === $ticket->claimed_by_user_id)>{{ $mate->name }}</option>
                        @endforeach
                    </select>
                    <button class="btn compact-btn" type="submit">Lempar Tiket</button>
                </form>
            @endif
        @endif
    </div>
    @endif
</section>

@if($ticket->status !== 'closed' && $ticket->approval_status !== 'waiting')
<section class="card qris-panel section">
    <div class="qris-toolbar"><h2>Tandai Selesai</h2></div>
    <form method="post" action="{{ route('ma.wa-tickets.close', $ticket) }}" class="pad" style="display:flex; flex-direction:column; gap:10px">
        @csrf
        <textarea name="note" rows="2" maxlength="2000" placeholder="Catatan penutup buat toko (opsional)..."></textarea>
        <div><button class="btn primary compact-btn" type="submit">Tandai Selesai &amp; Tutup Tiket</button></div>
    </form>
</section>
@endif

<section class="card qris-panel section">
    <div class="qris-toolbar">
        <h2>Percakapan</h2>
        <div class="ma-tabs">
            <button class="btn compact-btn active" type="button" data-wa-tab="toko">Ke Toko</button>
            <button class="btn compact-btn" type="button" data-wa-tab="internal">&#128274; Internal</button>
        </div>
    </div>

    <div data-wa-panel="toko">
        <div class="ticket-thread">
            @forelse($toToko as $message)
                <div class="ticket-message {{ $message->is_staff ? 'staff' : 'store' }}">
                    <div class="ticket-message-meta"><strong>{{ $message->is_staff ? 'Tim '.ucfirst($ticket->department) : ($message->user->name ?? 'Toko') }}</strong><span class="muted">{{ $message->created_at->timezone('Asia/Jakarta')->format('d M Y H:i') }}</span></div>
                    <div class="ticket-message-body">{{ $message->body }}</div>
                </div>
            @empty
                <p class="muted">Belum ada pesan ke toko.</p>
            @endforelse
        </div>
        @if($ticket->status !== 'closed' && $ticket->approval_status !== 'waiting')
            <form method="post" action="{{ route('ma.wa-tickets.reply', $ticket) }}" class="ticket-reply-form">
                @csrf
                <input type="hidden" name="is_internal" value="0">
                <textarea name="body" rows="3" maxlength="2000" required placeholder="Tulis update buat toko..."></textarea>
                <button class="btn primary compact-btn" type="submit">Kirim ke Toko</button>
            </form>
        @endif
    </div>

    <div data-wa-panel="internal" hidden>
        <div class="ticket-thread">
            @forelse($internal as $message)
                <div class="ticket-message staff">
                    <div class="ticket-message-meta"><strong>{{ $message->user->name ?? 'Sistem' }}</strong><span class="muted">{{ $message->created_at->timezone('Asia/Jakarta')->format('d M Y H:i') }}</span></div>
                    <div class="ticket-message-body">{{ $message->body }}</div>
                </div>
            @empty
                <p class="muted">Belum ada diskusi internal.</p>
            @endforelse
        </div>
        @if($ticket->status !== 'closed' && $ticket->approval_status !== 'waiting')
            <form method="post" action="{{ route('ma.wa-tickets.reply', $ticket) }}" class="ticket-reply-form">
                @csrf
                <input type="hidden" name="is_internal" value="1">
                <textarea name="body" rows="3" maxlength="2000" required placeholder="Diskusi internal (toko nggak lihat)..."></textarea>
                <button class="btn compact-btn" type="submit">Kirim Internal</button>
            </form>
        @endif
    </div>
</section>
@endsection

@push('scripts')
<script>
(function () {
    var buttons = document.querySelectorAll('[data-wa-tab]');
    var panels = document.querySelectorAll('[data-wa-panel]');
    buttons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var key = btn.dataset.waTab;
            buttons.forEach(function (b) { b.classList.toggle('active', b === btn); });
            panels.forEach(function (p) { p.hidden = p.dataset.waPanel !== key; });
        });
    });
})();
</script>
@endpush
