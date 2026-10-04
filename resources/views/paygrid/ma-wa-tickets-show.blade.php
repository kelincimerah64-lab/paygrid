@extends('layouts.paygrid')

@php
    $statusLabel = fn ($status) => App\Support\PayGridLabels::status($status);
    $statusPillClass = match ($ticket->status) {
        'closed' => 'closed',
        'in_progress' => 'progress',
        default => 'open',
    };
    $toToko = $ticket->messages->where('is_internal', false);
    $internal = $ticket->messages->where('is_internal', true);
    $initials = fn (?string $name) => $name ? strtoupper(substr(trim($name), 0, 1).substr(trim(strrchr(' '.$name, ' ')), 1, 1)) : '?';
@endphp

@section('content')
<div class="wat-hero">
    <div>
        <p class="eyebrow">{{ $ticket->merchant?->name ?: '-' }} &middot; {{ ucfirst($ticket->department) }}</p>
        <h1>{{ $ticket->ticket_no }}</h1>
    </div>
    <div style="display:flex; align-items:center; gap:10px">
        <span class="wat-pill {{ $statusPillClass }}">{{ $statusLabel($ticket->status) }}</span>
        @if(in_array(auth()->user()->role, ['ma', 'superadmin'], true))
            <a class="btn compact-btn" href="{{ route('ma.wa-tickets.index') }}">&larr; Kembali</a>
        @elseif(auth()->user()->role === 'approver')
            <a class="btn compact-btn" href="{{ route('wa-tickets.pending') }}">&larr; Kembali</a>
        @else
            <a class="btn compact-btn" href="{{ route('dept-tickets.index') }}">&larr; Kembali</a>
        @endif
    </div>
</div>

@if(session('status'))
    <section class="card pad section"><span class="badge ok">{{ session('status') }}</span></section>
@endif

<section class="card pad">
    <div class="wat-meta-row">
        <div class="wat-meta-chip"><span>Kategori</span><strong>{{ $ticket->category }}</strong></div>
        <div class="wat-meta-chip"><span>Dibuat</span><strong>{{ $ticket->created_at->timezone('Asia/Jakarta')->format('d M Y, H:i') }} WIB</strong></div>
        @foreach($ticket->metadata ?? [] as $key => $value)
            <div class="wat-meta-chip"><span>{{ ucfirst(str_replace('_', ' ', $key)) }}</span><strong>{{ is_numeric($value) ? 'Rp '.number_format((float) $value, 0, ',', '.') : $value }}</strong></div>
        @endforeach
    </div>
    <p style="margin:0; white-space:pre-wrap; font-size:13.5px; color:var(--ink)">{{ $ticket->description }}</p>

    @if($ticket->approval_status === 'waiting')
        <div class="wat-approval-card waiting">
            <div class="wat-approval-text"><b>&#9203; Menunggu Approval</b><span>Tiket ini butuh persetujuan sebelum bisa dikerjakan.</span></div>
            @if($canApprove && !$isCreator)
                <div class="wat-approval-actions">
                    <form method="post" action="{{ route('wa-tickets.approve', $ticket) }}">@csrf<button class="wat-btn approve" type="submit">&#10003; Approve</button></form>
                    <form method="post" action="{{ route('wa-tickets.reject', $ticket) }}">@csrf<button class="wat-btn reject" type="submit">&#10005; Reject</button></form>
                </div>
            @elseif($canApprove && $isCreator)
                <span class="wat-pill">Tidak bisa approve tiket buatan sendiri</span>
            @else
                <span class="wat-pill">Menunggu approval dari tim Approval</span>
            @endif
        </div>
    @elseif($ticket->approval_status === 'rejected')
        <div class="wat-approval-card rejected">
            <div class="wat-approval-text"><b>&#10005; Ditolak</b><span>Oleh {{ $ticket->approval_by }}{{ $ticket->approval_note ? ' — '.$ticket->approval_note : '' }}</span></div>
        </div>
    @else
        @if($ticket->approval_status === 'approved')
            <div style="margin:14px 0 0"><span class="wat-pill approved">&#10003; Disetujui oleh {{ $ticket->approval_by }}</span></div>
        @endif

        @if(!$ticket->claimed_by_user_id)
            <div class="wat-claim-box">
                <div class="wat-claim-who"><div class="wat-avatar">?</div><div><b>Belum ada yang pegang</b><span>Tiket ini masih di antrean</span></div></div>
                @unless(auth()->user()->role === 'approver')
                    <form method="post" action="{{ route('wa-tickets.claim', $ticket) }}">
                        @csrf
                        <button class="wat-btn primary" type="submit" @disabled($ticket->status === 'closed')>Ambil Tiket Ini</button>
                    </form>
                @endunless
            </div>
        @else
            <div class="wat-claim-box held">
                <div class="wat-claim-who">
                    <div class="wat-avatar">{{ $initials($ticket->claimedBy?->name) }}</div>
                    <div><b>{{ $ticket->claimedBy?->name }}</b><span>Diambil {{ $ticket->claimed_at?->timezone('Asia/Jakarta')->diffForHumans() }}</span></div>
                </div>
                @if($ticket->status !== 'closed' && auth()->user()->role !== 'approver')
                    <form method="post" action="{{ route('wa-tickets.transfer', $ticket) }}" class="wat-transfer-form">
                        @csrf
                        <select name="to_user_id">
                            <option value="">&#8634; Lepas ke Antrean</option>
                            @foreach($teammates as $mate)
                                <option value="{{ $mate->id }}" @selected($mate->id === $ticket->claimed_by_user_id)>{{ $mate->name }}</option>
                            @endforeach
                        </select>
                        <button class="wat-btn outline" type="submit">&#8646; Lempar Tiket</button>
                    </form>
                @endif
            </div>
        @endif
    @endif
</section>

@if($ticket->status !== 'closed' && $ticket->approval_status !== 'waiting' && auth()->user()->role !== 'approver')
<section class="wat-panel">
    <div class="wat-panel-head"><h2>Tandai Selesai</h2></div>
    <form method="post" action="{{ route('wa-tickets.close', $ticket) }}" class="wat-close-card">
        @csrf
        <textarea name="note" rows="2" maxlength="2000" placeholder="Catatan penutup buat toko (opsional)..."></textarea>
        <div><button class="wat-btn success" type="submit">&#10003; Tandai Selesai &amp; Tutup Tiket</button></div>
    </form>
</section>
@endif

<section class="wat-panel">
    <div class="wat-panel-head">
        <h2>Percakapan</h2>
        <div class="wat-tabs">
            <button class="wat-tab active" type="button" data-wa-tab="toko">Ke Toko</button>
            <button class="wat-tab" type="button" data-wa-tab="internal">&#128274; Internal</button>
        </div>
    </div>

    <div data-wa-panel="toko">
        <div class="wat-thread">
            @forelse($toToko as $message)
                @if($message->is_staff === false)
                    <div class="wat-msg in"><span class="wat-msg-who">{{ $message->user->name ?? 'Toko' }}</span>{{ $message->body }}<span class="wat-msg-time">{{ $message->created_at->timezone('Asia/Jakarta')->format('d M, H:i') }}</span></div>
                @else
                    <div class="wat-msg out"><span class="wat-msg-who">Tim {{ ucfirst($ticket->department) }}</span>{{ $message->body }}<span class="wat-msg-time">{{ $message->created_at->timezone('Asia/Jakarta')->format('d M, H:i') }}</span></div>
                @endif
            @empty
                <p class="wat-empty">Belum ada pesan ke toko.</p>
            @endforelse
        </div>
        @if($ticket->status !== 'closed' && $ticket->approval_status !== 'waiting' && auth()->user()->role !== 'approver')
            <form method="post" action="{{ route('wa-tickets.reply', $ticket) }}" class="wat-composer">
                @csrf
                <input type="hidden" name="is_internal" value="0">
                <textarea name="body" maxlength="2000" required placeholder="Tulis update buat toko..."></textarea>
                <button class="wat-btn primary" type="submit">Kirim</button>
            </form>
        @endif
    </div>

    <div data-wa-panel="internal" hidden>
        <div class="wat-thread">
            @forelse($internal as $message)
                <div class="wat-msg internal"><span class="wat-msg-who">{{ $message->user->name ?? 'Sistem' }}</span>{{ $message->body }}<span class="wat-msg-time">{{ $message->created_at->timezone('Asia/Jakarta')->format('d M, H:i') }}</span></div>
            @empty
                <p class="wat-empty">Belum ada diskusi internal. Cuma tim CS yang lihat ini, toko nggak bisa baca.</p>
            @endforelse
        </div>
        @if($ticket->status !== 'closed' && $ticket->approval_status !== 'waiting' && auth()->user()->role !== 'approver')
            <form method="post" action="{{ route('wa-tickets.reply', $ticket) }}" class="wat-composer internal">
                @csrf
                <input type="hidden" name="is_internal" value="1">
                <textarea name="body" maxlength="2000" required placeholder="Diskusi internal (toko nggak lihat)..."></textarea>
                <button class="wat-btn outline" type="submit">Kirim</button>
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
