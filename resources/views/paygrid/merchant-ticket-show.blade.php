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
    $approvalLabel = match ($ticket->approval_status) { 'waiting' => 'Menunggu Approval', 'approved' => 'Disetujui', 'rejected' => 'Ditolak', default => null };
    $approvalClass = match ($ticket->approval_status) { 'approved' => 'ok', 'rejected' => 'danger', default => 'warn' };
    $ticketsService = app(App\Services\MerchantTicketService::class);
    $readersFor = function ($message) use ($views) {
        return $views->filter(fn ($v) => $v->user_id !== $message->user_id && $v->last_viewed_at->gte($message->created_at))
            ->map(fn ($v) => $v->user?->name)->filter()->values();
    };
    $senderName = fn ($message) => $message->is_staff ? ($message->user->name ?? $deptLabel) : ($message->user->name ?? 'Toko');
    $senderRole = fn ($message) => $message->is_staff ? 'Tim Support' : 'Toko';
    $initials = fn ($name) => collect(explode(' ', trim($name ?: '?')))->map(fn ($w) => mb_substr($w, 0, 1))->take(2)->implode('');
    $messagesByDate = $ticket->messages->groupBy(fn ($m) => $m->created_at->timezone('Asia/Jakarta')->format('Y-m-d'));
@endphp

@section('content')
<div class="ticket-detail-crumb"><a href="{{ route('merchant.tickets.index', $merchant) }}">Semua Tiket</a> / <span>Detail Tiket</span></div>

@if(session('status'))
    <section class="card pad section"><span class="badge ok">{{ session('status') }}</span></section>
@endif

<div class="ticket-detail-title-row">
    <h1>{{ $ticket->title ?: $categoryLabel }}</h1>
    <span class="ticket-status-pill {{ $statusClass($ticket->status) }}">{{ $statusLabel($ticket->status) }}</span>
    @if($approvalLabel)<span class="badge {{ $approvalClass }}">{{ $approvalLabel }}</span>@endif
</div>
<p class="ticket-detail-sub">{{ $ticket->ticket_no }} &middot; {{ $deptLabel }} &middot; Dibuat {{ $ticket->created_at->timezone('Asia/Jakarta')->format('d M Y, H:i') }}</p>

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
                    @php $isMine = $message->user_id === auth()->id(); $readers = $readersFor($message); $name = $senderName($message); @endphp
                    <div class="ticket-msg {{ $isMine ? 'mine' : '' }}">
                        <div class="ticket-msg-head">
                            <div class="ticket-avatar" aria-hidden="true">{{ $initials($name) }}</div>
                            <div class="ticket-msg-who"><strong>{{ $name }}</strong><span>{{ $senderRole($message) }} &middot; {{ $message->created_at->timezone('Asia/Jakarta')->format('H:i') }}@if($message->edited_at) &middot; diedit @endif</span></div>
                        </div>
                        <div class="ticket-msg-bubble" data-msg-body>{{ $message->body }}</div>
                        @if($isMine)
                            <button type="button" class="ticket-msg-edit-trigger" data-edit-trigger>Edit</button>
                            <form method="post" action="{{ route('merchant.tickets.messages.update', [$merchant, $ticket, $message]) }}" class="ticket-msg-edit-form" hidden>
                                @csrf @method('PATCH')
                                <textarea name="body" maxlength="2000" required>{{ $message->body }}</textarea>
                                <div class="ticket-msg-edit-actions">
                                    <button type="submit" class="primary">Simpan</button>
                                    <button type="button" class="cancel" data-edit-cancel>Batal</button>
                                </div>
                            </form>
                        @endif
                        @if($readers->isNotEmpty())
                            <span class="ticket-msg-read">&#128065; Dibaca: {{ $readers->join(', ') }}</span>
                        @endif
                    </div>
                @endforeach
            @empty
                <p class="muted">Belum ada balasan.</p>
            @endforelse
        </div>

        @if($ticket->status !== 'closed')
            <form method="post" action="{{ route('merchant.tickets.reply', [$merchant, $ticket]) }}" class="ticket-reply-box">
                @csrf
                <label>Balas</label>
                <textarea name="body" rows="3" maxlength="2000" required placeholder="Tulis balasan..."></textarea>
                <div class="ticket-reply-footer">
                    <span></span>
                    <button class="btn primary compact-btn" type="submit">Kirim Balasan &#10148;</button>
                </div>
            </form>
        @else
            <p class="muted" style="padding:14px 18px; border-top:1px solid var(--line)">Tiket ini sudah ditutup.</p>
        @endif
    </div>

    <aside class="ticket-detail-aside">
        <div class="ticket-aside-card">
            <h3>Informasi Tiket</h3>
            <div class="ticket-info-row"><span>Tujuan</span><strong>{{ $deptLabel }}</strong></div>
            <div class="ticket-info-row"><span>Menu</span><strong>{{ $categoryLabel }}</strong></div>
            <div class="ticket-info-row"><span>Dibuat</span><strong>{{ $ticket->created_at->timezone('Asia/Jakarta')->format('d M Y, H:i') }}</strong></div>
            <div class="ticket-info-row"><span>Status</span><strong>{{ $statusLabel($ticket->status) }}</strong></div>
            @if($ticket->approval_status === 'rejected' && $ticket->approval_note)
                <p class="muted" style="margin-top:8px; font-size:12px"><strong>Alasan penolakan:</strong> {{ $ticket->approval_note }}</p>
            @endif
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
        </div>
    </aside>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/paygrid-realtime.js') }}?v={{ filemtime(public_path('js/paygrid-realtime.js')) }}"></script>
<script>
(function () {
    function autoGrow(textarea) {
        textarea.style.height = 'auto';
        textarea.style.height = textarea.scrollHeight + 'px';
    }

    document.querySelectorAll('[data-edit-trigger]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var msg = btn.closest('.ticket-msg');
            if (!msg) return;
            var body = msg.querySelector('[data-msg-body]');
            var form = msg.querySelector('.ticket-msg-edit-form');
            if (body) body.hidden = true;
            if (form) {
                form.hidden = false;
                var textarea = form.querySelector('textarea');
                if (textarea) {
                    autoGrow(textarea);
                    textarea.addEventListener('input', function () { autoGrow(textarea); });
                    textarea.focus();
                    textarea.setSelectionRange(textarea.value.length, textarea.value.length);
                }
            }
        });
    });
    document.querySelectorAll('[data-edit-cancel]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var msg = btn.closest('.ticket-msg');
            if (!msg) return;
            var body = msg.querySelector('[data-msg-body]');
            var form = msg.querySelector('.ticket-msg-edit-form');
            if (form) form.hidden = true;
            if (body) body.hidden = false;
        });
    });

    // This page doesn't have the staff thread's scroll/field-preservation
    // machinery, so a live-swap on new-message would risk losing an
    // in-progress reply or jumping the scroll position - reload is simpler
    // and safe here, just skipped while the reply box is actively focused.
    if (window.PayGridRealtime) {
        var realtime = window.PayGridRealtime.connect({
            key: @json(config('broadcasting.connections.reverb.key')),
            host: @json(config('broadcasting.connections.reverb.options.host')),
            port: @json(config('broadcasting.connections.reverb.options.port')),
            scheme: @json(config('broadcasting.connections.reverb.options.scheme')),
        });
        realtime.subscribePrivate('private-ticket.{{ $ticket->id }}', 'message.sent', function () {
            var replyBox = document.querySelector('.ticket-reply-box textarea');
            if (document.activeElement === replyBox) return;
            window.location.reload();
        });
    }
})();
</script>
@endpush
