@extends('layouts.paygrid')

@php
    $statusLabel = fn ($status) => match ($status) { 'open' => 'Belum Diproses', 'closed' => 'Selesai', default => App\Support\PayGridLabels::status($status) };
    $statusClass = fn ($status) => match ($status) {
        'closed' => 'ok',
        'in_progress', 'open' => 'warn',
        default => 'danger',
    };
    $deptLabel = app(App\Services\MerchantTicketService::class)->departmentLabel($ticket->department);
    $categoryLabel = app(App\Services\MerchantTicketService::class)->categoryLabel($ticket->department, $ticket->category);
    $ticketsService = app(App\Services\MerchantTicketService::class);
    $senderName = fn ($message) => $message->is_staff ? ($message->user->name ?? $deptLabel) : ($message->user->name ?? 'Toko');
    $senderRole = fn ($message) => $message->is_staff ? 'Admin' : 'Toko';
    $initials = fn ($name) => collect(explode(' ', trim($name ?: '?')))->map(fn ($w) => mb_substr($w, 0, 1))->take(2)->implode('');
    $messagesByDate = $ticket->messages->groupBy(fn ($m) => $m->created_at->timezone('Asia/Jakarta')->format('Y-m-d'));
@endphp

@section('content')
<div class="ticket-detail-crumb"><a href="{{ route('dept-tickets.index') }}">Semua Tiket</a> / <span>Detail Tiket</span></div>

@if(session('status'))
    <section class="card pad section"><span class="badge ok">{{ session('status') }}</span></section>
@endif

<div class="ticket-detail-title-row">
    <h1>{{ $categoryLabel }}</h1>
    <span class="ticket-status-pill {{ $statusClass($ticket->status) }}">{{ $statusLabel($ticket->status) }}</span>
</div>
<p class="ticket-detail-sub">{{ $deptLabel }} &middot; Dibuat {{ $ticket->created_at->timezone('Asia/Jakarta')->format('d M Y, H:i') }}</p>

<div class="ticket-detail-grid">
    <div class="ticket-chat-card">
        <div class="ticket-chat-head">
            <h3>Percakapan</h3>
            <span>{{ $ticket->messages->count() }} pesan</span>
        </div>
        <div class="ticket-thread">
            @forelse($messagesByDate as $dateKey => $dayMessages)
                <div class="ticket-date-divider"><span></span>{{ \Carbon\CarbonImmutable::parse($dateKey)->format('d M Y') }}<span></span></div>
                @foreach($dayMessages as $message)
                    @php $name = $senderName($message); $isMine = $message->user_id === auth()->id(); @endphp
                    <div class="ticket-msg {{ $isMine ? 'mine' : '' }}">
                        <div class="ticket-msg-head">
                            <div class="ticket-avatar" aria-hidden="true">{{ $initials($name) }}</div>
                            <div class="ticket-msg-who"><strong>{{ $name }}</strong><span>{{ $senderRole($message) }} &middot; {{ $message->created_at->timezone('Asia/Jakarta')->format('H:i') }}</span></div>
                        </div>
                        <div class="ticket-msg-bubble">{{ $message->body }}</div>
                    </div>
                @endforeach
            @empty
                <p class="muted">Belum ada balasan.</p>
            @endforelse
        </div>

        @if($ticket->status !== 'closed')
            <form method="post" action="{{ route('dept-tickets.reply', $ticket) }}" class="ticket-reply-box">
                @csrf
                <label>Balas ke toko</label>
                <textarea name="body" rows="3" maxlength="2000" required placeholder="Tulis balasan ke toko..."></textarea>
                <div class="ticket-reply-footer">
                    <span class="ticket-reply-attach">&#128206; Lampirkan</span>
                    <button class="btn primary compact-btn" type="submit">Kirim Balasan &#10148;</button>
                </div>
            </form>
        @else
            <p class="muted" style="padding:14px 18px; border-top:1px solid var(--line)">Tiket ini sudah ditutup.</p>
        @endif
    </div>

    <aside class="ticket-detail-aside">
        <div class="ticket-aside-card">
            <h3>Update Status</h3>
            <form method="post" action="{{ route('dept-tickets.status', $ticket) }}">
                @csrf
                <label class="muted" style="display:block; font-size:11.5px; margin-bottom:6px">Status tiket</label>
                <select name="status">
                    <option value="open" @selected($ticket->status === 'open')>Belum Diproses</option>
                    <option value="in_progress" @selected($ticket->status === 'in_progress')>Diproses</option>
                    <option value="closed" @selected($ticket->status === 'closed')>Selesai</option>
                </select>
                <button class="btn" type="submit" style="width:100%">Simpan Status</button>
            </form>
        </div>

        <div class="ticket-aside-card">
            <h3>Informasi Tiket</h3>
            <div class="ticket-info-row"><span>Tujuan</span><strong>{{ $deptLabel }}</strong></div>
            <div class="ticket-info-row"><span>Menu</span><strong>{{ $categoryLabel }}</strong></div>
            <div class="ticket-info-row"><span>Dibuat</span><strong>{{ $ticket->created_at->timezone('Asia/Jakarta')->format('d M Y, H:i') }}</strong></div>
            <div class="ticket-info-row"><span>Status</span><strong>{{ $statusLabel($ticket->status) }}</strong></div>
            @if(!empty($ticket->metadata))
                @foreach($ticket->metadata as $key => $value)
                    @continue($value === null || $value === '')
                    <div class="ticket-info-row"><span>{{ $ticketsService->fieldLabel($ticket->category, $key) }}</span><strong>{{ is_array($value) ? implode(', ', $value) : ($ticketsService->fieldType($ticket->category, $key) === 'number' ? 'Rp'.number_format((float) $value, 0, ',', '.') : $value) }}</strong></div>
                @endforeach
            @endif
        </div>

        <div class="ticket-aside-card">
            <h3>Deskripsi</h3>
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
    </aside>
</div>
@endsection
